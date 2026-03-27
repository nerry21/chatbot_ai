<?php

namespace App\Services\Booking;

use App\Services\Support\PhoneNumberService;
use Illuminate\Support\Carbon;

class BookingSlotExtractorService
{
    /**
     * @var array<string, int>
     */
    private const NUMBER_WORDS = [
        'satu' => 1,
        'dua' => 2,
        'tiga' => 3,
        'empat' => 4,
        'lima' => 5,
        'enam' => 6,
        'tujuh' => 7,
        'delapan' => 8,
        'sembilan' => 9,
        'sepuluh' => 10,
    ];

    private const AFFIRMATIONS = [
        'ya', 'iya', 'benar', 'sudah', 'oke', 'ok', 'siap', 'sesuai', 'mantap', 'lanjut',
    ];

    private const REJECTIONS = [
        'tidak', 'nggak', 'enggak', 'bukan', 'salah', 'ubah', 'ganti', 'koreksi', 'batal',
    ];

    public function __construct(
        private readonly RouteValidationService $routeValidator,
        private readonly PhoneNumberService $phoneService,
    ) {}

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
            'greeting_detected' => $this->isGreeting($normalized),
            'salam_type' => $this->hasIslamicGreeting($normalized) ? 'islamic' : null,
            'greeting_only' => $this->isGreetingOnly($normalized),
            'booking_keyword' => (bool) preg_match('/\b(book|booking|pesan|travel|berangkat|keberangkatan|jemput|antar|tujuan|rute)\b/u', $normalized),
            'schedule_keyword' => (bool) preg_match('/\b(jadwal|jam|slot|berangkat|keberangkatan|pagi|siang|sore|malam)\b/u', $normalized),
            'price_keyword' => (bool) preg_match('/\b(harga|ongkos|tarif|biaya)\b/u', $normalized),
            'route_keyword' => (bool) preg_match('/\b(rute|trayek|tujuan|lokasi|jemput|antar)\b/u', $normalized),
            'human_keyword' => (bool) preg_match('/\b(admin|manusia|operator|cs|customer service)\b/u', $normalized),
            'affirmation' => $this->matchesVocabulary($normalized, self::AFFIRMATIONS),
            'rejection' => $this->matchesVocabulary($normalized, self::REJECTIONS),
            'close_intent' => $this->isCloseIntent($normalized),
            'gratitude' => (bool) preg_match('/\b(makasih|terima kasih|thanks|thank you)\b/u', $normalized),
            'acknowledgement' => (bool) preg_match('/\b(ok|oke|baik|siap|sip|noted)\b/u', $normalized),
            'time_ambiguous' => false,
        ];

        $updates = [];

        $routeUpdates = $this->extractRouteSlots($text, $normalized, $expectedInput, $entityResult);

        if ($routeUpdates['pickup_location'] !== null) {
            $updates['pickup_location'] = $routeUpdates['pickup_location'];
        }

        if ($routeUpdates['destination'] !== null) {
            $updates['destination'] = $routeUpdates['destination'];
        }

        $passengerName = $this->extractPassengerName($text, $expectedInput, $entityResult);

        if ($passengerName !== null) {
            $updates['passenger_name'] = $passengerName;
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

        $paymentMethod = $this->extractPaymentMethod($normalized, $expectedInput, $entityResult);

        if ($paymentMethod !== null) {
            $updates['payment_method'] = $paymentMethod;
        }

        if (($currentSlots['contact_number'] ?? null) === null) {
            $contactNumber = $this->extractPhoneNumber($text);

            if ($contactNumber !== null && $contactNumber !== $senderPhone) {
                $updates['contact_number'] = $contactNumber;
                $updates['contact_same_as_sender'] = false;
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
     * @param  array<string, mixed>  $entityResult
     * @return array{pickup_location: string|null, destination: string|null}
     */
    public function extractRouteSlots(
        string $messageText,
        string $normalizedText,
        ?string $expectedInput,
        array $entityResult = [],
    ): array {
        $pickup = $this->normalizeLocationValue($entityResult['pickup_location'] ?? null);
        $destination = $this->normalizeLocationValue($entityResult['destination'] ?? null);

        $pickup ??= $this->extractLabeledLocation($messageText, [
            '/\b(?:titik\s+jemput(?:nya)?|lokasi\s+jemput(?:nya)?|pickup(?:\s+location)?|penjemputan)\s*(?:di|=|:)?\s*(.+)$/ui',
            '/\b(?:asal(?:nya)?|dari)\s+(.+?)\s+(?:ke|menuju)\b/ui',
            '/\b(?:jemput(?:nya)?\s+di)\s+(.+)$/ui',
        ]);

        $destination ??= $this->extractLabeledLocation($messageText, [
            '/\b(?:tujuan(?:nya)?|destinasi|antar(?:nya)?)\s*(?:ke|=|:)?\s*(.+)$/ui',
            '/\b(?:ke|menuju)\s+(.+)$/ui',
        ]);

        if ($pickup === null || $destination === null) {
            $routePair = $this->extractCompactRoutePair($messageText);
            $pickup ??= $routePair['pickup_location'];
            $destination ??= $routePair['destination'];
        }

        if ($expectedInput === 'pickup_location' && $pickup === null) {
            $pickup = $this->extractMenuLocation($normalizedText) ?? $this->extractLooseLocation($messageText);
        }

        if ($expectedInput === 'destination' && $destination === null) {
            $destination = $this->extractMenuLocation($normalizedText) ?? $this->extractLooseLocation($messageText);
        }

        return [
            'pickup_location' => $pickup,
            'destination' => $destination,
        ];
    }

    private function extractPassengerName(string $messageText, ?string $expectedInput, array $entityResult): ?string
    {
        $fromEntity = $entityResult['customer_name'] ?? null;

        if (is_string($fromEntity) && trim($fromEntity) !== '') {
            return $this->normalizePassengerName($fromEntity);
        }

        if (
            preg_match(
                '/\b(?:nama(?:\s+penumpang(?:nya)?)?|atas\s+nama|a\/n)\s*(?:adalah|=|:)?\s*([a-z][\p{L}\s\'.-]{1,60}?)(?=(?:\s*,|\s+jumlah\b|\s+\d+\s*(?:orang|penumpang)\b|\s+tanggal\b|\s+jam\b|\s+besok\b|\s+lusa\b|\s+hari\s+ini\b|\s+(?:metode|bayar)\b|$))/ui',
                $messageText,
                $matches,
            )
        ) {
            return $this->normalizePassengerName($matches[1] ?? null);
        }

        if ($expectedInput === 'passenger_name') {
            return $this->normalizePassengerName($messageText);
        }

        return null;
    }

    private function extractPassengerCount(string $normalizedText, ?string $expectedInput, array $entityResult): ?int
    {
        $entityCount = $entityResult['passenger_count'] ?? null;

        if (is_int($entityCount) && $entityCount > 0) {
            return $entityCount;
        }

        if ($expectedInput === 'passenger_count' && preg_match('/^\d{1,2}$/', $normalizedText)) {
            return (int) $normalizedText;
        }

        if (
            preg_match(
                '/\b(?:jumlah(?:nya)?|penumpang(?:nya)?|orang(?:nya)?)\s*(?:adalah|=|:)?\s*(\d{1,2}|satu|dua|tiga|empat|lima|enam|tujuh|delapan|sembilan|sepuluh)\b/u',
                $normalizedText,
                $matches,
            )
        ) {
            return $this->countFromToken($matches[1] ?? null);
        }

        if (preg_match('/\b(\d{1,2})\s*(orang|penumpang|org)\b/u', $normalizedText, $matches)) {
            return (int) $matches[1];
        }

        if (preg_match('/\bsendiri\b/u', $normalizedText)) {
            return 1;
        }

        if (preg_match('/\bberdua\b/u', $normalizedText)) {
            return 2;
        }

        foreach (self::NUMBER_WORDS as $token => $count) {
            if (preg_match('/\b'.preg_quote($token, '/').'\s*(orang|penumpang)\b/u', $normalizedText)) {
                return $count;
            }
        }

        if ($expectedInput === 'passenger_count') {
            foreach (self::NUMBER_WORDS as $token => $count) {
                if ($normalizedText === $token) {
                    return $count;
                }
            }

            if (preg_match('/^\d{1,2}\s*(orang|penumpang|org)?$/u', $normalizedText, $matches)) {
                return (int) preg_replace('/\D/u', '', $matches[0]);
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
                return Carbon::createSafe($year, $month, $day, 0, 0, 0, $this->timezone())
                    ->toDateString();
            } catch (\Throwable) {
                return null;
            }
        }

        if (preg_match('/\b(\d{1,2})\s+([a-z]+)(?:\s+(\d{4}))?\b/u', $text, $matches)) {
            $day = (int) $matches[1];
            $month = $this->monthFromText($matches[2] ?? '');
            $year = isset($matches[3]) ? (int) $matches[3] : $now->year;

            if ($month !== null) {
                try {
                    return Carbon::createSafe($year, $month, $day, 0, 0, 0, $this->timezone())
                        ->toDateString();
                } catch (\Throwable) {
                    return null;
                }
            }
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
                if (! is_string($alias) || $alias === '') {
                    continue;
                }

                $normalizedAlias = $this->normalizeText($alias);

                if (
                    ctype_digit($normalizedAlias)
                    && $expectedInput !== 'travel_time'
                    && $normalizedText !== $normalizedAlias
                ) {
                    continue;
                }

                if (str_contains(' '.$normalizedText.' ', ' '.$normalizedAlias.' ')) {
                    return ['time' => (string) ($slot['time'] ?? ''), 'ambiguous' => false];
                }
            }
        }

        if (preg_match('/\bjam\s+([01]?\d|2[0-3])(?:(?:[:.])([0-5]\d))?\b/u', $normalizedText, $matches)) {
            $minute = $matches[2] ?? '00';
            $resolved = $this->matchTimeToSlot($matches[1].':'.$minute);

            if ($resolved !== null) {
                return ['time' => $resolved, 'ambiguous' => false];
            }
        }

        if (preg_match('/\b([01]?\d|2[0-3])(?:[:.])([0-5]\d)\b/u', $normalizedText, $matches)) {
            $resolved = $this->matchTimeToSlot($matches[1].':'.$matches[2]);

            if ($resolved !== null) {
                return ['time' => $resolved, 'ambiguous' => false];
            }
        }

        if (preg_match('/\bsubuh\b/u', $normalizedText)) {
            return ['time' => '05:00', 'ambiguous' => false];
        }

        if (preg_match('/\bsiang\b/u', $normalizedText)) {
            return ['time' => '14:00', 'ambiguous' => false];
        }

        if (preg_match('/\bsore\b/u', $normalizedText)) {
            return ['time' => '16:00', 'ambiguous' => false];
        }

        if (preg_match('/\bmalam\b/u', $normalizedText)) {
            return ['time' => '19:00', 'ambiguous' => false];
        }

        if (preg_match('/\bpagi\b/u', $normalizedText)) {
            return ['time' => null, 'ambiguous' => true];
        }

        return ['time' => null, 'ambiguous' => false];
    }

    private function extractPaymentMethod(string $normalizedText, ?string $expectedInput, array $entityResult): ?string
    {
        $fromEntity = $entityResult['payment_method'] ?? null;

        if (is_string($fromEntity)) {
            $resolved = $this->normalizePaymentMethod($fromEntity);

            if ($resolved !== null) {
                return $resolved;
            }
        }

        foreach ((array) config('chatbot.jet.payment_methods', []) as $index => $method) {
            $aliases = array_merge(
                [(string) ($method['id'] ?? '')],
                is_array($method['aliases'] ?? null) ? $method['aliases'] : [],
            );

            foreach ($aliases as $alias) {
                if (! is_string($alias) || trim($alias) === '') {
                    continue;
                }

                if (str_contains(' '.$normalizedText.' ', ' '.$this->normalizeText($alias).' ')) {
                    return (string) ($method['id'] ?? null);
                }
            }

            if ($expectedInput === 'payment_method' && ctype_digit($normalizedText) && ((int) $normalizedText) === ($index + 1)) {
                return (string) ($method['id'] ?? null);
            }
        }

        return null;
    }

    private function extractPhoneNumber(string $messageText): ?string
    {
        if (! preg_match('/(?:\+?62|0)\d{8,15}/', $messageText, $matches)) {
            return null;
        }

        $normalized = $this->phoneService->toE164($matches[0]);

        return $normalized !== '' ? $normalized : null;
    }

    /**
     * @param  array<int, string>  $patterns
     */
    private function extractLabeledLocation(string $messageText, array $patterns): ?string
    {
        foreach ($patterns as $pattern) {
            if (! preg_match($pattern, $messageText, $matches)) {
                continue;
            }

            $candidate = $this->cleanLocationCapture((string) ($matches[1] ?? ''));

            if ($candidate !== null) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return array{pickup_location: string|null, destination: string|null}
     */
    private function extractCompactRoutePair(string $messageText): array
    {
        if (preg_match('/\bdari\s+(.+?)\s+ke\s+(.+)$/ui', $messageText, $matches)) {
            return [
                'pickup_location' => $this->cleanLocationCapture((string) ($matches[1] ?? '')),
                'destination' => $this->cleanLocationCapture((string) ($matches[2] ?? '')),
            ];
        }

        $knownLocations = [];

        foreach ($this->routeValidator->allKnownLocations() as $location) {
            $needle = ' '.$this->normalizeText($location).' ';
            $haystack = ' '.$this->normalizeText($messageText).' ';
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

        if (count($ordered) >= 2) {
            return [
                'pickup_location' => $this->routeValidator->normalizeLocation($ordered[0]),
                'destination' => $this->routeValidator->normalizeLocation($ordered[1]),
            ];
        }

        if (count($ordered) === 1) {
            $single = $ordered[0];
            $normalized = $this->normalizeText($messageText);
            $singleKey = $this->normalizeText($single);

            if (preg_match('/\bke\s+'.preg_quote($singleKey, '/').'\b/u', $normalized)) {
                return [
                    'pickup_location' => null,
                    'destination' => $this->routeValidator->normalizeLocation($single),
                ];
            }

            if (preg_match('/\b(dari|jemput di|asal)\s+'.preg_quote($singleKey, '/').'\b/u', $normalized)) {
                return [
                    'pickup_location' => $this->routeValidator->normalizeLocation($single),
                    'destination' => null,
                ];
            }
        }

        return [
            'pickup_location' => null,
            'destination' => null,
        ];
    }

    private function extractLooseLocation(string $messageText): ?string
    {
        $candidate = trim($messageText);

        if ($candidate === '' || mb_strlen($candidate) > 60) {
            return null;
        }

        if (preg_match('/\b(nama|jumlah|tanggal|jam|metode|bayar|penumpang)\b/ui', $candidate)) {
            return null;
        }

        return $this->normalizeLocationValue($candidate);
    }

    private function cleanLocationCapture(string $value): ?string
    {
        $clean = trim($value, " \t\n\r\0\x0B,.;:-");
        $clean = preg_replace('/(?:,|;)\s*(?:tujuan(?:nya)?|destinasi|antar(?:nya)?|nama|jumlah|penumpang|tanggal|jam|metode|bayar)\b.*$/ui', '', $clean) ?? $clean;
        $clean = preg_replace('/\s+(?:ke|menuju)\s+[a-z][\p{L}\s.-]*$/ui', '', $clean) ?? $clean;
        $clean = preg_replace('/\b(apakah|ada|tersedia|ya|kak|admin|min|dong|nih|untuk|tanggal|jam|jumlah|nama|metode|bayar|besok|lusa|hari ini|pagi|siang|sore|malam)\b.*$/ui', '', $clean) ?? $clean;
        $clean = trim($clean, " \t\n\r\0\x0B,.;:-");

        if ($clean === '' || mb_strlen($clean) < 3) {
            return null;
        }

        return $this->normalizeLocationValue($clean);
    }

    private function normalizeLocationValue(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return $this->routeValidator->normalizeLocation($value);
    }

    private function normalizePassengerName(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $clean = trim($value);
        $clean = preg_replace('/^(?:nama(?:\s+saya)?|atas\s+nama|a\/n)\s*/ui', '', $clean) ?? $clean;
        $clean = preg_replace('/^(?:saya|sy|aku)\s+/ui', '', $clean) ?? $clean;
        $clean = preg_replace('/\b(jumlah|tanggal|jam|metode|bayar)\b.*$/ui', '', $clean) ?? $clean;
        $clean = trim($clean, " \t\n\r\0\x0B,.;:-");

        if ($clean === '' || mb_strlen($clean) > 60) {
            return null;
        }

        if (preg_match('/\d/', $clean)) {
            return null;
        }

        return mb_convert_case($clean, MB_CASE_TITLE, 'UTF-8');
    }

    private function extractMenuLocation(string $normalizedText): ?string
    {
        if (! ctype_digit($normalizedText)) {
            return null;
        }

        return $this->routeValidator->menuLocationByOrder((int) $normalizedText);
    }

    private function countFromToken(mixed $token): ?int
    {
        if (is_string($token) && ctype_digit($token)) {
            return (int) $token;
        }

        return is_string($token) ? (self::NUMBER_WORDS[$token] ?? null) : null;
    }

    private function normalizePaymentMethod(string $value): ?string
    {
        $normalized = $this->normalizeText($value);

        foreach ((array) config('chatbot.jet.payment_methods', []) as $method) {
            $aliases = array_merge(
                [(string) ($method['id'] ?? '')],
                is_array($method['aliases'] ?? null) ? $method['aliases'] : [],
            );

            foreach ($aliases as $alias) {
                if (! is_string($alias) || trim($alias) === '') {
                    continue;
                }

                if ($normalized === $this->normalizeText($alias)) {
                    return (string) ($method['id'] ?? null);
                }
            }
        }

        return null;
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
            && ! preg_match('/\b(harga|ongkos|jadwal|pesan|booking|berangkat|jemput|antar|rute|tujuan)\b/u', $normalizedText);
    }

    /**
     * @param  array<int, string>  $vocabulary
     */
    private function matchesVocabulary(string $normalizedText, array $vocabulary): bool
    {
        foreach ($vocabulary as $word) {
            if ($normalizedText === $word || preg_match('/\b'.preg_quote($word, '/').'\b/u', $normalizedText)) {
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

        return $months[$this->normalizeText($month)] ?? null;
    }

    private function normalizeText(string $value): string
    {
        $normalized = mb_strtolower(trim($value), 'UTF-8');
        $normalized = str_replace(["\u{2019}", "'"], '', $normalized);
        $normalized = preg_replace('/[^\p{L}\p{N}\s:\/.-]/u', ' ', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+/u', ' ', trim($normalized)) ?? trim($normalized);

        return $normalized;
    }

    private function timezone(): string
    {
        return (string) config('chatbot.jet.timezone', 'Asia/Jakarta');
    }
}
