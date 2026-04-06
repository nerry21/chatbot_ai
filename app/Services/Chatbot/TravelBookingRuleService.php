<?php

namespace App\Services\Chatbot;

use App\Services\Booking\FareCalculatorService;
use Carbon\Carbon;

/**
 * TravelBookingRuleService
 *
 * Stateless business-rule helpers for JET booking flow:
 *   - Passenger count validation
 *   - Departure slot resolution
 *   - Schedule-change eligibility (4-hour window)
 *   - Abandoned-booking follow-up timing (15-minute intervals)
 *   - Booking and schedule-change review text
 *
 * Config source: chatbot.jet.*
 */
class TravelBookingRuleService
{
    /**
     * Latest schedule change allowed before departure (hours).
     * There is no runtime config key for this — it is a fixed business rule.
     */
    private const SCHEDULE_CHANGE_HOURS_BEFORE = 4;

    /**
     * Minutes of user silence before the first follow-up is sent.
     */
    private const FIRST_FOLLOW_UP_MINUTES = 15;

    /**
     * Minutes after the first follow-up before the booking is auto-cancelled.
     */
    private const SECOND_TIMEOUT_MINUTES = 15;

    public function __construct(
        private readonly FareCalculatorService $fareCalculator,
    ) {}

    // ─── Passenger ────────────────────────────────────────────────────────────

    public function getMaxNormalPassengers(): int
    {
        return (int) config('chatbot.jet.passenger.standard_max', 5);
    }

    public function getMaxPassengersWithConfirmation(): int
    {
        return (int) config('chatbot.jet.passenger.manual_confirm_max', 6);
    }

    /**
     * @return array{valid: bool, requires_confirmation: bool, message: string}
     */
    public function validatePassengerCount(int $count): array
    {
        $maxNormal = $this->getMaxNormalPassengers();
        $maxWithConfirm = $this->getMaxPassengersWithConfirmation();

        if ($count <= 0) {
            return [
                'valid' => false,
                'requires_confirmation' => false,
                'message' => 'Jumlah penumpang belum valid. Mohon isi jumlah penumpang yang benar.',
            ];
        }

        if ($count <= $maxNormal) {
            return [
                'valid' => true,
                'requires_confirmation' => false,
                'message' => 'Jumlah penumpang valid.',
            ];
        }

        if ($count <= $maxWithConfirm) {
            return [
                'valid' => true,
                'requires_confirmation' => true,
                'message' => 'Jumlah penumpang masih memungkinkan, namun perlu konfirmasi lebih lanjut.',
            ];
        }

        return [
            'valid' => false,
            'requires_confirmation' => false,
            'message' => 'Jumlah penumpang melebihi batas yang dapat diproses oleh bot.',
        ];
    }

    // ─── Confirmation ─────────────────────────────────────────────────────────

    /**
     * Whether the user's reply is a positive confirmation ("iya", "benar", "setuju", etc.).
     * Matches exact normalized words to avoid false positives on partial matches.
     */
    public function isConfirmationText(string $text): bool
    {
        $normalized = $this->normalizeText($text);

        $confirmWords = [
            'iya', 'ya', 'yep', 'yap', 'oke', 'ok', 'okay',
            'benar', 'betul', 'setuju', 'sesuai', 'confirm', 'lanjut',
            'sudah benar', 'sudah betul', 'sudah sesuai', 'ya benar', 'iya benar',
            'siap', 'bisa',
        ];

        foreach ($confirmWords as $word) {
            if ($normalized === $word
                || $normalized === $word.' kak'
                || $normalized === $word.' min'
                || $normalized === $word.' admin'
            ) {
                return true;
            }
        }

        return false;
    }

    // ─── Departure Slots ──────────────────────────────────────────────────────

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getDepartureTimes(): array
    {
        /** @var array<int, array<string, mixed>> $slots */
        $slots = config('chatbot.jet.departure_slots', []);

        return $slots;
    }

    /**
     * Attempt to match free-form text to a known departure slot.
     * Checks aliases and HH:MM patterns.
     *
     * @return array<string, mixed>|null
     */
    public function findDepartureTime(string $text): ?array
    {
        $normalized = $this->normalizeText($text);

        foreach ($this->getDepartureTimes() as $slot) {
            $time = substr((string) ($slot['time'] ?? ''), 0, 5);

            // Direct time match (e.g. "08:00")
            if ($time !== '' && str_contains($normalized, $time)) {
                return $slot;
            }

            // Alias match
            foreach ((array) ($slot['aliases'] ?? []) as $alias) {
                $normalizedAlias = $this->normalizeText((string) $alias);

                if ($normalizedAlias !== '' && str_contains(' '.$normalized.' ', ' '.$normalizedAlias.' ')) {
                    return $slot;
                }
            }
        }

        return null;
    }

    // ─── Schedule Change ──────────────────────────────────────────────────────

    /**
     * Determine whether a schedule change is still allowed.
     *
     * @return array{allowed: bool, hours_limit: int, minutes_remaining: int, message: string}
     */
    public function canChangeSchedule(Carbon $departureDateTime, ?Carbon $now = null): array
    {
        $now ??= now(config('chatbot.jet.timezone', 'Asia/Jakarta'));

        $hoursLimit = self::SCHEDULE_CHANGE_HOURS_BEFORE;
        $minutesRemaining = (int) $now->diffInMinutes($departureDateTime, false);
        $allowedMinutes = $hoursLimit * 60;
        $allowed = $minutesRemaining >= $allowedMinutes;

        return [
            'allowed' => $allowed,
            'hours_limit' => $hoursLimit,
            'minutes_remaining' => $minutesRemaining,
            'message' => $allowed
                ? 'Perubahan jadwal masih bisa diproses.'
                : "Perubahan jadwal hanya bisa dilakukan paling lambat {$hoursLimit} jam sebelum keberangkatan.",
        ];
    }

    // ─── Abandoned Booking Follow-up ──────────────────────────────────────────

    public function shouldTriggerFirstFollowUp(Carbon $lastUserMessageAt, ?Carbon $now = null): bool
    {
        $now ??= now(config('chatbot.jet.timezone', 'Asia/Jakarta'));

        return $lastUserMessageAt->diffInMinutes($now) >= self::FIRST_FOLLOW_UP_MINUTES;
    }

    public function shouldCancelAfterSecondTimeout(Carbon $lastBotFollowUpAt, ?Carbon $now = null): bool
    {
        $now ??= now(config('chatbot.jet.timezone', 'Asia/Jakarta'));

        return $lastBotFollowUpAt->diffInMinutes($now) >= self::SECOND_TIMEOUT_MINUTES;
    }

    public function getFollowUpMessage(?string $lastStepLabel = null): string
    {
        $step = ($lastStepLabel !== null && trim($lastStepLabel) !== '')
            ? $lastStepLabel
            : 'yang terakhir';

        return "Mohon maaf mengganggu, Bapak/Ibu. Apakah masih ingin melanjutkan proses {$step}? Kami siap bantu.";
    }

    // ─── Review Builders ──────────────────────────────────────────────────────

    /**
     * Build a customer-facing booking review text from raw field array.
     *
     * Preferred usage: use BookingReviewFormatterService with a BookingRequest model.
     * This method is provided for callers that only have a plain array (e.g. pre-persist).
     *
     * @param  array<string, mixed>  $data  Keys: passenger_count, departure_date, departure_time,
     *                                       seat, pickup_point, pickup_address, dropoff_point,
     *                                       passenger_names, contact_number
     */
    public function buildBookingReview(array $data): string
    {
        $origin = (string) ($data['pickup_point'] ?? '');
        $destination = (string) ($data['dropoff_point'] ?? '');
        $unitFare = $this->fareCalculator->unitFare($origin ?: null, $destination ?: null);

        $formattedFare = $unitFare !== null
            ? $this->fareCalculator->formatRupiah($unitFare)
            : 'Belum tersedia';

        return implode("\n", [
            'Review perjalanan:',
            '- Jumlah penumpang : '.($data['passenger_count'] ?? '-'),
            '- Tanggal          : '.($data['departure_date'] ?? '-'),
            '- Jam              : '.($data['departure_time'] ?? '-'),
            '- Seat             : '.($data['seat'] ?? '-'),
            '- Penjemputan      : '.($origin !== '' ? $origin : '-'),
            '- Alamat jemput    : '.($data['pickup_address'] ?? '-'),
            '- Pengantaran      : '.($destination !== '' ? $destination : '-'),
            '- Nama penumpang   : '.$this->formatPassengerNames($data['passenger_names'] ?? []),
            '- Nomor kontak     : '.($data['contact_number'] ?? '-'),
            '- Ongkos           : '.$formattedFare,
            '',
            'Apakah data perjalanan ini sudah benar, Bapak/Ibu?',
        ]);
    }

    /**
     * Build a customer-facing schedule-change review text.
     *
     * @param  array<string, mixed>  $data
     */
    public function buildScheduleChangeReview(array $data): string
    {
        $origin = (string) ($data['pickup_point'] ?? '');
        $destination = (string) ($data['dropoff_point'] ?? '');
        $unitFare = $this->fareCalculator->unitFare($origin ?: null, $destination ?: null);

        $formattedFare = $unitFare !== null
            ? $this->fareCalculator->formatRupiah($unitFare)
            : 'Belum tersedia';

        return implode("\n", [
            'Review perubahan jadwal:',
            '- Tanggal baru     : '.($data['departure_date'] ?? '-'),
            '- Jam baru         : '.($data['departure_time'] ?? '-'),
            '- Seat baru        : '.($data['seat'] ?? '-'),
            '- Penjemputan baru : '.($origin !== '' ? $origin : '-'),
            '- Alamat jemput    : '.($data['pickup_address'] ?? '-'),
            '- Pengantaran baru : '.($destination !== '' ? $destination : '-'),
            '- Ongkos           : '.$formattedFare,
        ]);
    }

    // ─── Private ──────────────────────────────────────────────────────────────

    private function formatPassengerNames(mixed $names): string
    {
        if (is_string($names) && trim($names) !== '') {
            return $names;
        }

        if (is_array($names) && $names !== []) {
            $filtered = array_values(array_filter(
                array_map(fn ($item) => trim((string) $item), $names),
                fn ($item) => $item !== '',
            ));

            return $filtered !== [] ? implode(', ', $filtered) : '-';
        }

        return '-';
    }

    private function normalizeText(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = str_replace(['\u{2019}', "'"], '', $text);
        $text = preg_replace('/[^\p{L}\p{N}\s:]/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }
}
