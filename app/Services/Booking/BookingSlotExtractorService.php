<?php

namespace App\Services\Booking;

use App\Services\Support\PhoneNumberService;
use Illuminate\Support\Carbon;

class BookingSlotExtractorService
{
    private const AFFIRMATIONS = [
        'ya', 'iya', 'benar', 'sudah', 'oke', 'ok', 'siap', 'sesuai', 'mantab', 'mantap',
    ];

    private const REJECTIONS = [
        'tidak', 'nggak', 'enggak', 'bukan', 'salah', 'ubah', 'ganti', 'koreksi', 'batal',
    ];

    public function __construct(
        private readonly RouteValidationService $routeValidator,
        private readonly PhoneNumberService $phoneService,
    ) {
    }

    /**
     * @param  array<string, mixed>  $currentSlots
     * @param  array<string, mixed>  $entityResult
     * @return array{updates: array<string, mixed>, signals: array<string, mixed>}
     */
    public function extract(
        string $messageText,
        array $currentSlots,
        array $entityResult,
        ?string $expectedInput,
        string $senderPhone,
    ): array {
        $text = trim($messageText);
        $normalized = $this->normalizeText($text);

        $signals = [
            'greeting_detected'  => $this->isGreeting($normalized),
            'salam_type'         => $this->hasIslamicGreeting($normalized) ? 'islamic' : null,
            'greeting_only'      => $this->isGreetingOnly($normalized),
            'booking_keyword'    => (bool) preg_match('/\b(book|booking|pesan|travel|berangkat|keberangkatan|jemput|antar|seat|kursi)\b/u', $normalized),
            'schedule_keyword'   => (bool) preg_match('/\b(jadwal|berangkat|keberangkatan|hari ini|besok|slot|mobil)\b/u', $normalized),
            'price_keyword'      => (bool) preg_match('/\b(harga|ongkos|tarif|biaya)\b/u', $normalized),
            'route_keyword'      => (bool) preg_match('/\b(rute|trayek|tujuan|lokasi|jemput|antar)\b/u', $normalized),
            'human_keyword'      => (bool) preg_match('/\b(admin|manusia|operator|cs|customer service)\b/u', $normalized),
            'affirmation'        => $this->matchesVocabulary($normalized, self::AFFIRMATIONS),
            'rejection'          => $this->matchesVocabulary($normalized, self::REJECTIONS),
            'close_intent'       => $this->isCloseIntent($normalized),
            'time_ambiguous'     => false,
        ];

        $updates = [];

        if ($signals['greeting_detected']) {
            $updates['greeting_detected'] = true;
            $updates['salam_type'] = $signals['salam_type'];
        }

        $passengerCount = $this->extractPassengerCount($normalized, $expectedInput, $entityResult);

        if ($passengerCount !== null) {
            $updates['passenger_count'] = $passengerCount;
        }

        $date = $this->extractDate($text, $entityResult);

        if ($date !== null) {
            $updates['travel_date'] = $date;
        }

        $timeResult = $this->extractTime($normalized, $expectedInput, $entityResult);

        if ($timeResult['time'] !== null) {
            $updates['travel_time'] = $timeResult['time'];
        }

        $signals['time_ambiguous'] = $timeResult['ambiguous'];

        $routePair = $this->extractRoutePair($text, $entityResult);

        if ($routePair['pickup_point'] !== null) {
            $updates['pickup_point'] = $routePair['pickup_point'];
        }

        if ($routePair['destination_point'] !== null) {
            $updates['destination_point'] = $routePair['destination_point'];
        }

        if ($expectedInput === 'pickup_point' && $routePair['pickup_point'] === null) {
            $pickup = $this->extractMenuLocation($normalized) ?? $this->extractSingleLocation($text);

            if ($pickup !== null) {
                $updates['pickup_point'] = $pickup;
            }
        }

        if ($expectedInput === 'destination_point' && $routePair['destination_point'] === null) {
            $destination = $this->extractMenuLocation($normalized) ?? $this->extractSingleLocation($text);

            if ($destination !== null) {
                $updates['destination_point'] = $destination;
            }
        }

        if ($expectedInput === 'pickup_full_address' && $text !== '') {
            $updates['pickup_full_address'] = $text;
        }

        if ($expectedInput === 'contact_number') {
            if ($normalized === 'sama' || str_contains($normalized, 'nomor ini')) {
                $updates['contact_same_as_sender'] = true;
                $updates['contact_number'] = $senderPhone;
            } else {
                $contact = $this->extractPhoneNumber($text);

                if ($contact !== null) {
                    $updates['contact_same_as_sender'] = false;
                    $updates['contact_number'] = $contact;
                }
            }
        }

        if ($expectedInput === 'passenger_names') {
            $names = $this->extractPassengerNames($text);

            if ($names !== []) {
                $updates['passenger_names'] = $names;
            }
        }

        if ($expectedInput === 'selected_seats') {
            $seats = $this->extractSeatSelection($text, $currentSlots['seat_choices_available'] ?? []);

            if ($seats !== []) {
                $updates['selected_seats'] = $seats;
            }
        }

        if (($currentSlots['contact_number'] ?? null) === null && ! isset($updates['contact_number'])) {
            $contact = $this->extractPhoneNumber($text);

            if ($contact !== null && $expectedInput !== 'pickup_full_address') {
                $updates['contact_same_as_sender'] = false;
                $updates['contact_number'] = $contact;
            }
        }

        return [
            'updates' => $updates,
            'signals' => $signals,
        ];
    }

    public function isCloseIntent(string $normalizedText): bool
    {
        $closeWords = array_map(
            fn (mixed $phrase) => $this->normalizeText((string) $phrase),
            config('chatbot.guards.close_intents', []),
        );

        return in_array($normalizedText, $closeWords, true);
    }

    /**
     * @return array{pickup_point: string|null, destination_point: string|null}
     */
    public function extractRoutePair(string $messageText, array $entityResult = []): array
    {
        $pickup = $this->routeValidator->knownLocation($entityResult['pickup_location'] ?? null);
        $destination = $this->routeValidator->knownLocation($entityResult['destination'] ?? null);

        if ($pickup !== null || $destination !== null) {
            return [
                'pickup_point'      => $pickup,
                'destination_point' => $destination,
            ];
        }

        $normalized = $this->normalizeText($messageText);
        $knownLocations = [];

        foreach ($this->routeValidator->allKnownLocations() as $location) {
            $needle = ' ' . $this->normalizeText($location) . ' ';
            $haystack = ' ' . $normalized . ' ';
            $position = mb_strpos($haystack, $needle);

            if ($position !== false) {
                $knownLocations[] = [
                    'location' => $location,
                    'position' => $position,
                ];
            }
        }

        usort($knownLocations, fn (array $left, array $right): int => $left['position'] <=> $right['position']);

        $ordered = array_values(array_unique(array_map(
            fn (array $item) => $item['location'],
            $knownLocations,
        )));

        if (count($ordered) === 1) {
            $single = $ordered[0];
            $singleKey = $this->normalizeText($single);

            if (preg_match('/\bke\s+' . preg_quote($singleKey, '/') . '\b/u', $normalized)) {
                return [
                    'pickup_point' => null,
                    'destination_point' => $single,
                ];
            }

            if (preg_match('/\b(dari|jemput di|asal)\s+' . preg_quote($singleKey, '/') . '\b/u', $normalized)) {
                return [
                    'pickup_point' => $single,
                    'destination_point' => null,
                ];
            }
        }

        return [
            'pickup_point'      => $ordered[0] ?? null,
            'destination_point' => $ordered[1] ?? null,
        ];
    }

    /**
     * @param  array<int, string>  $availableSeats
     * @return array<int, string>
     */
    public function extractSeatSelection(string $messageText, array $availableSeats): array
    {
        $parts = preg_split('/[,;\n\/]+/u', $messageText) ?: [];
        $selection = [];

        foreach ($parts as $part) {
            $trimmed = trim($part);

            if ($trimmed === '') {
                continue;
            }

            if (ctype_digit($trimmed)) {
                $index = (int) $trimmed - 1;

                if (isset($availableSeats[$index])) {
                    $selection[] = $availableSeats[$index];
                }

                continue;
            }

            foreach ($availableSeats as $seat) {
                if ($this->normalizeText($seat) === $this->normalizeText($trimmed)) {
                    $selection[] = $seat;
                    break;
                }
            }
        }

        return array_values(array_unique($selection));
    }

    /**
     * @return array<int, string>
     */
    public function extractPassengerNames(string $messageText): array
    {
        $parts = preg_split('/[\n,;\/]+|\s+dan\s+/u', trim($messageText)) ?: [];

        return array_values(array_filter(array_map(
            fn (string $name) => trim($name),
            $parts,
        )));
    }

    public function extractSingleLocation(string $messageText): ?string
    {
        return $this->routeValidator->findLocationInText($messageText);
    }

    private function extractMenuLocation(string $normalizedText): ?string
    {
        if (! ctype_digit($normalizedText)) {
            return null;
        }

        return $this->routeValidator->menuLocationByOrder((int) $normalizedText);
    }

    private function extractPassengerCount(string $normalizedText, ?string $expectedInput, array $entityResult): ?int
    {
        $entityCount = $entityResult['passenger_count'] ?? null;

        if (is_int($entityCount) && $entityCount > 0) {
            return $entityCount;
        }

        if ($expectedInput === 'passenger_count' && ctype_digit($normalizedText)) {
            return (int) $normalizedText;
        }

        if (preg_match('/\b(\d{1,2})\s*(orang|penumpang)?\b/u', $normalizedText, $matches)) {
            return (int) $matches[1];
        }

        $vocabulary = [
            'sendiri' => 1,
            'satu'    => 1,
            'berdua'  => 2,
            'dua'     => 2,
            'tiga'    => 3,
            'empat'   => 4,
            'lima'    => 5,
            'enam'    => 6,
            'tujuh'   => 7,
            'delapan' => 8,
        ];

        foreach ($vocabulary as $word => $count) {
            if (preg_match('/\b' . preg_quote($word, '/') . '\b/u', $normalizedText)) {
                return $count;
            }
        }

        return null;
    }

    private function extractDate(string $messageText, array $entityResult): ?string
    {
        $fromEntity = $entityResult['departure_date'] ?? null;

        if (is_string($fromEntity) && trim($fromEntity) !== '') {
            try {
                return Carbon::parse($fromEntity, $this->timezone())->toDateString();
            } catch (\Throwable) {
            }
        }

        $text = $this->normalizeText($messageText);
        $now = Carbon::now($this->timezone());

        if (str_contains($text, 'hari ini')) {
            return $now->toDateString();
        }

        if (str_contains($text, 'besok')) {
            return $now->copy()->addDay()->toDateString();
        }

        if (str_contains($text, 'lusa')) {
            return $now->copy()->addDays(2)->toDateString();
        }

        if (preg_match('/\b(\d{1,2})[\/\-](\d{1,2})(?:[\/\-](\d{2,4}))?\b/u', $text, $matches)) {
            $year = isset($matches[3]) ? (int) $matches[3] : $now->year;
            $year = $year < 100 ? 2000 + $year : $year;
            try {
                return Carbon::createSafe($year, (int) $matches[2], (int) $matches[1], 0, 0, 0, $this->timezone())
                    ->toDateString();
            } catch (\Throwable) {
                return null;
            }
        }

        if (preg_match('/\btanggal\s+(\d{1,2})(?:\s+([a-z]+))?(?:\s+(\d{4}))?\b/u', $text, $matches)) {
            $day = (int) $matches[1];
            $month = $this->monthFromText($matches[2] ?? '') ?? $now->month;
            $year = isset($matches[3]) ? (int) $matches[3] : $now->year;
            try {
                $candidate = Carbon::createSafe($year, $month, $day, 0, 0, 0, $this->timezone());
            } catch (\Throwable) {
                return null;
            }

            if (! isset($matches[2]) && $candidate->lt($now->copy()->startOfDay())) {
                $candidate->addMonth();
            }

            return $candidate->toDateString();
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $entityResult
     * @return array{time: string|null, ambiguous: bool}
     */
    private function extractTime(string $normalizedText, ?string $expectedInput, array $entityResult): array
    {
        $fromEntity = $entityResult['departure_time'] ?? null;

        if (is_string($fromEntity) && trim($fromEntity) !== '') {
            $resolved = $this->matchTimeToSlot($fromEntity);

            if ($resolved !== null) {
                return ['time' => $resolved, 'ambiguous' => false];
            }
        }

        if ($expectedInput === 'travel_time' && ctype_digit($normalizedText)) {
            $fromMenu = $this->departureTimeByOrder((int) $normalizedText);

            if ($fromMenu !== null) {
                return ['time' => $fromMenu, 'ambiguous' => false];
            }
        }

        foreach ($this->departureSlots() as $slot) {
            foreach ($slot['aliases'] ?? [] as $alias) {
                if ($alias !== '' && str_contains(' ' . $normalizedText . ' ', ' ' . $this->normalizeText($alias) . ' ')) {
                    return ['time' => $slot['time'], 'ambiguous' => false];
                }
            }
        }

        if (preg_match('/\bjam\s+([01]?\d|2[0-3])(?:(?:[:.])([0-5]\d))?\b/u', $normalizedText, $matches)) {
            $minute = $matches[2] ?? '00';
            $resolved = $this->matchTimeToSlot($matches[1] . ':' . $minute);

            if ($resolved !== null) {
                return ['time' => $resolved, 'ambiguous' => false];
            }
        }

        if (preg_match('/\b([01]?\d|2[0-3])(?:[:.])([0-5]\d)\b/u', $normalizedText, $matches)) {
            $resolved = $this->matchTimeToSlot($matches[1] . ':' . $matches[2]);

            if ($resolved !== null) {
                return ['time' => $resolved, 'ambiguous' => false];
            }
        }

        if (preg_match('/\bpagi\b/u', $normalizedText)) {
            return ['time' => null, 'ambiguous' => true];
        }

        return ['time' => null, 'ambiguous' => false];
    }

    private function extractPhoneNumber(string $messageText): ?string
    {
        if (! preg_match('/(?:\+?62|0)\d{8,15}/', $messageText, $matches)) {
            return null;
        }

        $normalized = $this->phoneService->toE164($matches[0]);

        return $normalized !== '' ? $normalized : null;
    }

    private function hasIslamicGreeting(string $normalizedText): bool
    {
        return (bool) preg_match('/\b(assalamualaikum|assalamu alaikum|ass wr wb|ass wr\. wb|salam)\b/u', $normalizedText);
    }

    private function isGreeting(string $normalizedText): bool
    {
        return $this->hasIslamicGreeting($normalizedText)
            || (bool) preg_match('/\b(halo|hai|hello|selamat pagi|selamat siang|selamat sore|selamat malam)\b/u', $normalizedText);
    }

    private function isGreetingOnly(string $normalizedText): bool
    {
        return $this->isGreeting($normalizedText)
            && ! preg_match('/\b(harga|ongkos|jadwal|pesan|booking|berangkat|jemput|antar|seat|kursi|rute)\b/u', $normalizedText);
    }

    /**
     * @param  array<int, string>  $vocabulary
     */
    private function matchesVocabulary(string $normalizedText, array $vocabulary): bool
    {
        foreach ($vocabulary as $word) {
            if ($normalizedText === $word || preg_match('/\b' . preg_quote($word, '/') . '\b/u', $normalizedText)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function departureSlots(): array
    {
        /** @var array<int, array<string, mixed>> $slots */
        $slots = config('chatbot.jet.departure_slots', []);

        return $slots;
    }

    private function departureTimeByOrder(int $order): ?string
    {
        foreach ($this->departureSlots() as $slot) {
            if ((int) ($slot['order'] ?? 0) === $order) {
                return (string) $slot['time'];
            }
        }

        return null;
    }

    private function matchTimeToSlot(string $candidate): ?string
    {
        $clean = str_replace('.', ':', trim($candidate));

        if (preg_match('/^\d{1,2}$/', $clean)) {
            $clean .= ':00';
        }

        foreach ($this->departureSlots() as $slot) {
            if ((string) ($slot['time'] ?? '') === $clean) {
                return $clean;
            }
        }

        return null;
    }

    private function monthFromText(string $month): ?int
    {
        $months = [
            'jan' => 1,
            'januari' => 1,
            'feb' => 2,
            'februari' => 2,
            'mar' => 3,
            'maret' => 3,
            'apr' => 4,
            'april' => 4,
            'mei' => 5,
            'jun' => 6,
            'juni' => 6,
            'jul' => 7,
            'juli' => 7,
            'agt' => 8,
            'agu' => 8,
            'agustus' => 8,
            'sep' => 9,
            'september' => 9,
            'okt' => 10,
            'oktober' => 10,
            'nov' => 11,
            'november' => 11,
            'des' => 12,
            'desember' => 12,
        ];

        $normalized = $this->normalizeText($month);

        return $months[$normalized] ?? null;
    }

    private function normalizeText(string $value): string
    {
        $normalized = mb_strtolower(trim($value), 'UTF-8');
        $normalized = str_replace(['’', "'"], '', $normalized);
        $normalized = preg_replace('/[^\p{L}\p{N}\s:\/.-]/u', ' ', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+/u', ' ', trim($normalized)) ?? trim($normalized);

        return $normalized;
    }

    private function timezone(): string
    {
        return (string) config('chatbot.jet.timezone', 'Asia/Jakarta');
    }
}
