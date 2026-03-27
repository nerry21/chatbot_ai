<?php

namespace App\Services\Booking;

use App\Enums\BookingStatus;
use App\Models\BookingRequest;
use App\Models\BookingSeatReservation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SeatAvailabilityService
{
    public const GLOBAL_TRIP_KEY = 'global';

    public function __construct(
        private readonly RouteValidationService $routeValidator,
    ) {}

    /**
     * @return array<int, string>
     */
    public function allSeatLabels(): array
    {
        /** @var array<int, string> $seats */
        $seats = config('chatbot.jet.seat_labels', []);

        return array_values($seats);
    }

    public function seatByOrder(int $order): ?string
    {
        $labels = $this->allSeatLabels();

        return $labels[$order - 1] ?? null;
    }

    /**
     * @return array<int, string>
     */
    public function availableSeats(
        string $travelDate,
        string $travelTime,
        ?int $excludeBookingId = null,
        ?string $tripKey = null,
    ): array
    {
        $this->purgeExpiredReservations();

        // Seat hold remains conservative per tanggal + jam untuk mencegah bentrok
        // lintas sesi aktif. trip_key tetap disimpan sebagai konteks draft/rute.
        $takenSeats = BookingSeatReservation::query()
            ->active()
            ->whereDate('departure_date', $travelDate)
            ->where('departure_time', $travelTime)
            ->when($excludeBookingId !== null, function ($query) use ($excludeBookingId): void {
                $query->where('booking_request_id', '!=', $excludeBookingId);
            })
            ->pluck('seat_code')
            ->all();

        return array_values(array_diff($this->allSeatLabels(), $takenSeats));
    }

    /**
     * @return array{
     *     trip_key: string|null,
     *     scope_key: string,
     *     available_seats: array<int, string>,
     *     available_count: int,
     *     required_count: int,
     *     has_capacity: bool,
     *     alternative_slots: array<int, array{id: string, label: string, time: string, available_count: int}>
     * }
     */
    public function availabilitySnapshot(BookingRequest $booking, ?int $requiredSeats = null): array
    {
        $tripKey = $this->tripKeyForBooking($booking);
        $scopeKey = $this->reservationScopeKey($tripKey);
        $requiredCount = max(1, $requiredSeats ?? (int) ($booking->passenger_count ?? 1));

        if ($booking->departure_date === null || $booking->departure_time === null) {
            return [
                'trip_key' => $tripKey,
                'scope_key' => $scopeKey,
                'available_seats' => [],
                'available_count' => 0,
                'required_count' => $requiredCount,
                'has_capacity' => false,
                'alternative_slots' => [],
            ];
        }

        $availableSeats = $this->availableSeats(
            $booking->departure_date->toDateString(),
            $booking->departure_time,
            $booking->id,
            $tripKey,
        );

        return [
            'trip_key' => $tripKey,
            'scope_key' => $scopeKey,
            'available_seats' => $availableSeats,
            'available_count' => count($availableSeats),
            'required_count' => $requiredCount,
            'has_capacity' => count($availableSeats) >= $requiredCount,
            'alternative_slots' => $this->alternativeDepartureSlots(
                travelDate: $booking->departure_date->toDateString(),
                neededSeats: $requiredCount,
                excludeBookingId: $booking->id,
                tripKey: $tripKey,
                excludeTime: $booking->departure_time,
            ),
        ];
    }

    /**
     * @return array<int, array{id: string, label: string, time: string, available_count: int}>
     */
    public function alternativeDepartureSlots(
        string $travelDate,
        int $neededSeats = 1,
        ?int $excludeBookingId = null,
        ?string $tripKey = null,
        ?string $excludeTime = null,
    ): array {
        $alternatives = [];

        foreach ((array) config('chatbot.jet.departure_slots', []) as $slot) {
            $time = (string) ($slot['time'] ?? '');

            if ($time === '' || $time === $excludeTime) {
                continue;
            }

            $availableCount = count($this->availableSeats($travelDate, $time, $excludeBookingId, $tripKey));

            if ($availableCount < max(1, $neededSeats)) {
                continue;
            }

            $alternatives[] = [
                'id' => (string) ($slot['id'] ?? $time),
                'label' => (string) ($slot['label'] ?? $time.' WIB'),
                'time' => $time,
                'available_count' => $availableCount,
            ];
        }

        return $alternatives;
    }

    /**
     * @param  array<int, string>  $requestedSeats
     * @return array<int, string>
     */
    public function reserveSeats(BookingRequest $booking, array $requestedSeats): array
    {
        if ($booking->departure_date === null || $booking->departure_time === null) {
            throw new RuntimeException('Tanggal dan jam keberangkatan belum lengkap.');
        }

        $normalizedSeats = $this->normalizeSeatSelection($requestedSeats);

        if ($normalizedSeats === []) {
            throw new RuntimeException('Seat yang dipilih tidak valid.');
        }

        $this->purgeExpiredReservations();
        $scopeKey = $this->reservationScopeKey($this->tripKeyForBooking($booking));

        try {
            DB::transaction(function () use ($booking, $normalizedSeats, $scopeKey): void {
                $booking->seatReservations()->delete();

                $conflicts = BookingSeatReservation::query()
                    ->active()
                    ->whereDate('departure_date', $booking->departure_date->toDateString())
                    ->where('departure_time', $booking->departure_time)
                    ->whereIn('seat_code', $normalizedSeats)
                    ->lockForUpdate()
                    ->pluck('seat_code')
                    ->all();

                if ($conflicts !== []) {
                    throw new RuntimeException('Seat yang dipilih baru saja terpakai.');
                }

                foreach ($normalizedSeats as $seatCode) {
                    BookingSeatReservation::create([
                        'booking_request_id' => $booking->id,
                        'departure_date'     => $booking->departure_date->toDateString(),
                        'departure_time'     => $booking->departure_time,
                        'trip_key'           => $scopeKey,
                        'seat_code'          => $seatCode,
                        'expires_at'         => $booking->booking_status === BookingStatus::Confirmed
                            ? null
                            : now()->addMinutes($this->seatHoldMinutes()),
                    ]);
                }
            });
        } catch (QueryException|RuntimeException $e) {
            throw new RuntimeException($e->getMessage(), previous: $e);
        }

        return $normalizedSeats;
    }

    public function confirmSeats(BookingRequest $booking): void
    {
        $booking->seatReservations()->update([
            'expires_at' => null,
            'trip_key' => $this->reservationScopeKey($this->tripKeyForBooking($booking)),
        ]);
    }

    public function releaseSeats(BookingRequest $booking): void
    {
        $booking->seatReservations()->delete();
    }

    public function syncDraftReservationContext(BookingRequest $booking): BookingRequest
    {
        if (! $booking->exists || ! $booking->hasSelectedSeats()) {
            return $booking;
        }

        if ($booking->departure_date === null || $booking->departure_time === null) {
            return $booking;
        }

        $scopeKey = $this->reservationScopeKey($this->tripKeyForBooking($booking));

        $booking->seatReservations()
            ->where(function ($query) use ($scopeKey): void {
                $query->whereNull('trip_key')
                    ->orWhere('trip_key', '!=', $scopeKey);
            })
            ->update(['trip_key' => $scopeKey]);

        return $booking->fresh();
    }

    /**
     * @param  array<int, string>  $selection
     * @return array<int, string>
     */
    public function normalizeSeatSelection(array $selection): array
    {
        $labels = $this->allSeatLabels();
        $lookup = [];

        foreach ($labels as $label) {
            $lookup[$this->normalizeSeatKey($label)] = $label;
        }

        $normalized = [];

        foreach ($selection as $seat) {
            $raw = trim((string) $seat);

            if (ctype_digit($raw)) {
                $raw = $this->seatByOrder((int) $raw) ?? $raw;
            }

            $key = $this->normalizeSeatKey($raw);

            if (isset($lookup[$key])) {
                $normalized[] = $lookup[$key];
            }
        }

        return array_values(array_unique($normalized));
    }

    private function purgeExpiredReservations(): void
    {
        BookingSeatReservation::query()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->delete();
    }

    private function seatHoldMinutes(): int
    {
        return max(5, (int) config('chatbot.jet.seat_hold_minutes', 30));
    }

    private function tripKeyForBooking(BookingRequest $booking): ?string
    {
        $derived = $this->routeValidator->tripKey(
            $booking->pickup_location,
            $booking->destination,
        );

        if ($derived !== null) {
            return $derived;
        }

        $stored = trim((string) ($booking->trip_key ?? ''));

        return $stored !== '' ? $stored : null;
    }

    private function reservationScopeKey(?string $tripKey): string
    {
        $normalized = trim((string) $tripKey);

        return $normalized !== '' ? $normalized : self::GLOBAL_TRIP_KEY;
    }

    private function normalizeSeatKey(string $value): string
    {
        $normalized = mb_strtolower(trim($value), 'UTF-8');
        $normalized = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+/u', ' ', trim($normalized)) ?? trim($normalized);

        return $normalized;
    }
}
