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
    /**
     * @return array<int, string>
     */
    public function allSeatLabels(): array
    {
        /** @var array<int, string> $seats */
        $seats = config('chatbot.jet.seat_labels', []);

        return array_values($seats);
    }

    /**
     * @return array<int, string>
     */
    public function availableSeats(string $travelDate, string $travelTime, ?int $excludeBookingId = null): array
    {
        $this->purgeExpiredReservations();

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

        try {
            DB::transaction(function () use ($booking, $normalizedSeats): void {
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
        $booking->seatReservations()->update(['expires_at' => null]);
    }

    public function releaseSeats(BookingRequest $booking): void
    {
        $booking->seatReservations()->delete();
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
            $key = $this->normalizeSeatKey((string) $seat);

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

    private function normalizeSeatKey(string $value): string
    {
        $normalized = mb_strtolower(trim($value), 'UTF-8');
        $normalized = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+/u', ' ', trim($normalized)) ?? trim($normalized);

        return $normalized;
    }
}
