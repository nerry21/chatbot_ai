<?php

namespace App\Services\Booking;

use App\Services\Chatbot\ConversationTextNormalizerService;
use App\Services\Chatbot\GreetingDetectorService;
use App\Services\Support\PhoneNumberService;
use Illuminate\Support\Carbon;

class BookingSlotExtractorService
{
    private const NUMBER_WORDS = [
        'satu' => 1, 'dua' => 2, 'tiga' => 3, 'empat' => 4, 'lima' => 5,
        'enam' => 6, 'tujuh' => 7, 'delapan' => 8, 'sembilan' => 9, 'sepuluh' => 10,
    ];

    public function __construct(
        private readonly RouteValidationService $routeValidator,
        private readonly PhoneNumberService $phoneService,
        private readonly SeatAvailabilityService $seatAvailability,
        private readonly GreetingDetectorService $greetingDetector,
        private readonly ConversationTextNormalizerService $textNormalizer,
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
        $greetingInspection = $this->greetingDetector->inspect($messageText);
        $updates = [];
        $signals = [
            'greeting_detected' => $greetingInspection['has_general_greeting'],
            'salam_type' => $greetingInspection['has_islamic_greeting'] ? 'islamic' : null,
            'greeting_only' => $greetingInspection['greeting_only'],
            'booking_keyword' => (bool) preg_match('/(?:\b(book|booking|pesan|reservasi)\b|\b(mau|ingin|nak)\s+(booking|pesan|travel|berangkat|naik)\b|\b(ikut|naik)\s+travel\b|\bpesan\s+travel\b)/u', $normalized),
            'schedule_keyword' => (bool) preg_match('/\b(jadwal|jam|slot|berangkat|keberangkatan|pagi|siang|sore|malam|subuh)\b/u', $normalized),
            'price_keyword' => (bool) preg_match('/\b(harga|ongkos|tarif|biaya)\b/u', $normalized),
            'route_keyword' => (bool) preg_match('/\b(rute|trayek|tujuan|lokasi|jemput|antar)\b/u', $normalized),
            'human_keyword' => (bool) preg_match('/\b(admin|manusia|operator|cs|customer service)\b/u', $normalized),
            'affirmation' => $this->textNormalizer->isAffirmative($messageText),
            'rejection' => $this->textNormalizer->isNegative($messageText),
            'change_request' => $this->textNormalizer->isNegative($messageText)
                || (bool) preg_match('/\b(ubah|ganti|koreksi|revisi)\b/u', $normalized),
            'close_intent' => $this->isCloseIntent($normalized),
            'gratitude' => (bool) preg_match('/\b(makasih|terima kasih|thanks|thank you)\b/u', $normalized),
            'acknowledgement' => (bool) preg_match('/\b(ok|oke|baik|siap|sip|noted|mantap)\b/u', $normalized),
            'today_schedule_keyword' => str_contains($normalized, 'hari ini')
                && (bool) preg_match('/\b(jadwal|berangkat|keberangkatan|jam|slot)\b/u', $normalized),
            'time_ambiguous' => false,
        ];

        if (! in_array($expectedInput, ['pickup_full_address', 'contact_number'], true)) {
            $route = $this->extractRouteSlots($text, $normalized, $expectedInput, $entityResult);
            if ($route['pickup_location'] !== null) {
                $updates['pickup_location'] = $route['pickup_location'];
            }
            if ($route['destination'] !== null) {
                $updates['destination'] = $route['destination'];
            }
        }

        $nameUpdates = $this->extractPassengerNames($text, $expectedInput, $entityResult, $currentSlots);
        if ($nameUpdates !== []) {
            $updates = array_merge($updates, $nameUpdates);
        }

        $count = $this->extractPassengerCount($normalized, $expectedInput, $entityResult);
        if ($count !== null) {
            $updates['passenger_count'] = $count;
        }

        $date = $this->extractDate($text, $entityResult);
        if ($date !== null) {
            $updates['travel_date'] = $date;
        }

        $time = $this->extractTime($normalized, $expectedInput, $entityResult);
        if ($time['time'] !== null) {
            $updates['travel_time'] = $time['time'];
        }
        $signals['time_ambiguous'] = $time['ambiguous'];

        $seats = $this->extractSeatSelection($text, $normalized, $expectedInput, $entityResult);
        if ($seats !== []) {
            $updates['selected_seats'] = $seats;
        }

        $address = $this->extractPickupAddress($text, $normalized, $expectedInput);
        if ($address !== null) {
            $updates['pickup_full_address'] = $address;
        }

        $payment = $this->extractPaymentMethod($normalized, $expectedInput, $entityResult);
        if ($payment !== null) {
            $updates['payment_method'] = $payment;
        }

        $contact = $this->extractContact($text, $normalized, $expectedInput, $senderPhone);
        if ($contact !== []) {
            $updates = array_merge($updates, $contact);
        }

        return ['updates' => $updates, 'signals' => $signals];
    }

    public function isCloseIntent(string $normalizedText): bool
    {
        $phrases = array_map(fn (mixed $phrase) => $this->normalizeText((string) $phrase), config('chatbot.guards.close_intents', []));

        return in_array($normalizedText, $phrases, true);
    }

    /**
     * @param  array<string, mixed>  $entityResult
     * @return array{pickup_location: string|null, destination: string|null}
     */
    public function extractRouteSlots(string $messageText, string $normalizedText, ?string $expectedInput, array $entityResult = []): array
    {
        $pickup = $this->normalizeLocation($entityResult['pickup_location'] ?? null);
        $destination = $this->normalizeLocation($entityResult['destination'] ?? null);

        $pickup ??= $this->extractLabeledLocation($messageText, [
            '/\b(?:titik\s+jemput|lokasi\s+jemput|pickup|penjemputan)\s*(?:di|=|:)?\s*(.+)$/ui',
            '/\b(?:asal|dari)\s+(.+?)\s+(?:ke|menuju)\b/ui',
            '/\bjemput(?:nya)?\s+di\s+(.+)$/ui',
        ]);
        $destination ??= $this->extractLabeledLocation($messageText, [
            '/\b(?:tujuan|destinasi|antar(?:nya)?)\s*(?:ke|=|:)?\s*(.+)$/ui',
            '/\b(?:ke|menuju)\s+(.+)$/ui',
        ]);

        if ($pickup === null || $destination === null) {
            $pair = $this->extractCompactRoutePair($messageText);
            $pickup ??= $pair['pickup_location'];
            $destination ??= $pair['destination'];
        }

        if ($expectedInput === 'pickup_location' && $pickup === null) {
            $pickup = $this->menuLocation($normalizedText) ?? $this->normalizeLocation($messageText);
        }

        if ($expectedInput === 'destination' && $destination === null) {
            $destination = $this->menuLocation($normalizedText) ?? $this->normalizeLocation($messageText);
        }

        return ['pickup_location' => $pickup, 'destination' => $destination];
    }

    private function extractPassengerNames(string $messageText, ?string $expectedInput, array $entityResult, array $currentSlots): array
    {
        $expectedCount = max(1, (int) ($currentSlots['passenger_count'] ?? 1));
        $raw = is_string($entityResult['customer_name'] ?? null) ? $entityResult['customer_name'] : null;

        if ($raw === null && preg_match('/\b(?:nama(?:-nama)?(?:\s+penumpang)?|atas\s+nama|a\/n)\s*(?:=|:)?\s*(.+)$/ui', $messageText, $matches)) {
            $raw = (string) ($matches[1] ?? '');
        }

        if ($raw === null && $expectedInput === 'passenger_name') {
            $raw = $messageText;
        }

        if ($raw === null) {
            return [];
        }

        $names = $this->splitNames($raw, $expectedCount);
        if ($names === []) {
            return [];
        }

        return ['passenger_name' => $names[0], 'passenger_names' => $names];
    }

    private function extractPassengerCount(string $normalizedText, ?string $expectedInput, array $entityResult): ?int
    {
        $entityCount = $entityResult['passenger_count'] ?? null;
        if (is_int($entityCount) && $entityCount > 0) {
            return $entityCount;
        }

        if ($expectedInput === 'passenger_count') {
            if (preg_match('/^\d{1,2}$/', $normalizedText)) {
                return (int) $normalizedText;
            }
            if (isset(self::NUMBER_WORDS[$normalizedText])) {
                return self::NUMBER_WORDS[$normalizedText];
            }
        }

        if (preg_match('/\b(?:jumlah|penumpang|orang)\s*(?:nya)?\s*(?:=|:|adalah)?\s*(\d{1,2}|satu|dua|tiga|empat|lima|enam|tujuh|delapan|sembilan|sepuluh)\b/u', $normalizedText, $matches)) {
            return $this->countFromToken($matches[1] ?? null);
        }
        if (preg_match('/\b(\d{1,2})\s*(orang|penumpang|org)\b/u', $normalizedText, $matches)) {
            return (int) $matches[1];
        }
        if (str_contains($normalizedText, 'sendiri')) {
            return 1;
        }
        if (str_contains($normalizedText, 'berdua')) {
            return 2;
        }

        return null;
    }

    private function extractDate(string $messageText, array $entityResult): ?string
    {
        $raw = $entityResult['departure_date'] ?? null;
        if (is_string($raw) && trim($raw) !== '') {
            try {
                return Carbon::parse($raw, $this->timezone())->toDateString();
            } catch (\Throwable) {
            }
        }

        $text = $this->normalizeText($messageText);
        $now = Carbon::now($this->timezone());
        if (str_contains($text, 'hari ini')) return $now->toDateString();
        if (str_contains($text, 'besok')) return $now->copy()->addDay()->toDateString();
        if (str_contains($text, 'lusa')) return $now->copy()->addDays(2)->toDateString();

        if (preg_match('/\b(\d{1,2})[\/\-](\d{1,2})(?:[\/\-](\d{2,4}))?\b/u', $text, $m)) {
            $year = isset($m[3]) ? (int) $m[3] : $now->year;
            $year = $year < 100 ? 2000 + $year : $year;
            try {
                return Carbon::createSafe($year, (int) $m[2], (int) $m[1], 0, 0, 0, $this->timezone())->toDateString();
            } catch (\Throwable) {
                return null;
            }
        }

        if (preg_match('/\b(\d{1,2})\s+([a-z]+)(?:\s+(\d{4}))?\b/u', $text, $m)) {
            $month = $this->monthFromText($m[2] ?? '');
            if ($month !== null) {
                try {
                    return Carbon::createSafe((int) ($m[3] ?? $now->year), $month, (int) $m[1], 0, 0, 0, $this->timezone())->toDateString();
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
        $raw = is_string($entityResult['departure_time'] ?? null) ? $entityResult['departure_time'] : null;
        if ($raw !== null && ($time = $this->matchTimeToSlot($raw)) !== null) {
            return ['time' => $time, 'ambiguous' => false];
        }

        if ($expectedInput === 'travel_time' && ctype_digit($normalizedText) && ($time = $this->departureTimeByOrder((int) $normalizedText)) !== null) {
            return ['time' => $time, 'ambiguous' => false];
        }

        foreach ($this->departureSlots() as $slot) {
            foreach ((array) ($slot['aliases'] ?? []) as $alias) {
                $normalizedAlias = $this->normalizeText((string) $alias);

                if (
                    ctype_digit($normalizedAlias)
                    && $expectedInput !== 'travel_time'
                    && $normalizedText !== $normalizedAlias
                ) {
                    continue;
                }

                if (str_contains(' '.$normalizedText.' ', ' '.$normalizedAlias.' ')) {
                    return ['time' => (string) $slot['time'], 'ambiguous' => false];
                }
            }
        }

        if (preg_match('/\b(?:jam\s+)?([01]?\d|2[0-3])(?:[:.])([0-5]\d)\b/u', $normalizedText, $matches)) {
            $time = $this->matchTimeToSlot($matches[1].':'.$matches[2]);
            if ($time !== null) {
                return ['time' => $time, 'ambiguous' => false];
            }
        }

        if (str_contains($normalizedText, 'pagi')) return ['time' => null, 'ambiguous' => true];
        if (str_contains($normalizedText, 'subuh')) return ['time' => '05:00', 'ambiguous' => false];
        if (str_contains($normalizedText, 'siang')) return ['time' => '14:00', 'ambiguous' => false];
        if (str_contains($normalizedText, 'sore')) return ['time' => '16:00', 'ambiguous' => false];
        if (str_contains($normalizedText, 'malam')) return ['time' => '19:00', 'ambiguous' => false];

        return ['time' => null, 'ambiguous' => false];
    }

    /**
     * @param  array<string, mixed>  $entityResult
     * @return array<int, string>
     */
    private function extractSeatSelection(string $messageText, string $normalizedText, ?string $expectedInput, array $entityResult): array
    {
        $entitySeats = $entityResult['selected_seats'] ?? $entityResult['seat_number'] ?? null;

        if (is_array($entitySeats) && $entitySeats !== []) {
            return $this->seatAvailability->normalizeSeatSelection($entitySeats);
        }

        if (is_string($entitySeats) && trim($entitySeats) !== '') {
            $selection = preg_split('/[\n,;\/]+/u', $entitySeats) ?: [$entitySeats];

            return $this->seatAvailability->normalizeSeatSelection($selection);
        }

        $explicit = $expectedInput === 'selected_seats' || (bool) preg_match('/\b(seat|kursi)\b/u', $normalizedText);
        $chunks = preg_split('/[\n,;\/]+/u', $messageText) ?: [$messageText];
        $selection = [];

        foreach ($chunks as $chunk) {
            $candidate = trim((string) preg_replace('/\b(?:seat|kursi)\b\s*/ui', '', $chunk));
            if ($candidate === '') {
                continue;
            }
            if ($explicit || in_array($this->normalizeText($candidate), array_map(fn (string $label) => $this->normalizeText($label), $this->seatAvailability->allSeatLabels()), true)) {
                $selection[] = $candidate;
            }
        }

        return $this->seatAvailability->normalizeSeatSelection($selection);
    }

    private function extractPickupAddress(string $messageText, string $normalizedText, ?string $expectedInput): ?string
    {
        if (preg_match('/\b(?:alamat(?:\s+lengkap)?(?:\s+jemput)?|detail\s+alamat)\s*(?:=|:)?\s*(.+)$/ui', $messageText, $matches)) {
            return $this->normalizeAddress((string) ($matches[1] ?? ''));
        }

        if ($expectedInput !== 'pickup_full_address' || $this->isCloseIntent($normalizedText) || $this->isGreetingOnly($normalizedText)) {
            return null;
        }

        return $this->normalizeAddress($messageText);
    }

    private function extractPaymentMethod(string $normalizedText, ?string $expectedInput, array $entityResult): ?string
    {
        $raw = is_string($entityResult['payment_method'] ?? null) ? $entityResult['payment_method'] : null;
        if ($raw !== null && ($resolved = $this->normalizePaymentMethod($raw)) !== null) {
            return $resolved;
        }

        foreach ((array) config('chatbot.jet.payment_methods', []) as $index => $method) {
            $aliases = array_merge([(string) ($method['id'] ?? '')], is_array($method['aliases'] ?? null) ? $method['aliases'] : []);
            foreach ($aliases as $alias) {
                if (str_contains(' '.$normalizedText.' ', ' '.$this->normalizeText((string) $alias).' ')) {
                    return (string) $method['id'];
                }
            }
            if ($expectedInput === 'payment_method' && ctype_digit($normalizedText) && ((int) $normalizedText) === ($index + 1)) {
                return (string) $method['id'];
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function extractContact(string $messageText, string $normalizedText, ?string $expectedInput, string $senderPhone): array
    {
        if ($expectedInput === 'contact_number' && preg_match('/\b(sama|nomor ini|pakai nomor ini|sama nomor ini|sama dengan nomor ini)\b/u', $normalizedText)) {
            return ['contact_number' => $senderPhone, 'contact_same_as_sender' => true];
        }

        if (! preg_match('/(?:\+?62|0)\d{8,15}/', $messageText, $matches)) {
            return [];
        }

        $phone = $this->phoneService->toE164($matches[0]);
        if ($phone === '') {
            return [];
        }

        return ['contact_number' => $phone, 'contact_same_as_sender' => $phone === $senderPhone];
    }

    private function extractLabeledLocation(string $messageText, array $patterns): ?string
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $messageText, $matches)) {
                $candidate = trim((string) ($matches[1] ?? ''), " \t\n\r\0\x0B,.;:-");
                $candidate = preg_replace('/(?:,|;)\s*(?:tujuan|destinasi|antar(?:nya)?|nama|jumlah|penumpang|tanggal|jam|alamat|metode|bayar)\b.*$/ui', '', $candidate) ?? $candidate;
                $candidate = preg_replace('/\b(?:tujuan|destinasi|antar(?:nya)?|nama|jumlah|penumpang|tanggal|jam|alamat|metode|bayar)\b.*$/ui', '', $candidate) ?? $candidate;
                $candidate = preg_replace('/\b(?:apakah|ada|tersedia|bisa|ya|dong|nih|tolong|mohon)\b.*$/ui', '', $candidate) ?? $candidate;
                $candidate = trim($candidate, " \t\n\r\0\x0B,.;:-");

                return $candidate !== '' ? $this->normalizeLocation($candidate) : null;
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
                'pickup_location' => $this->normalizeLocation((string) ($matches[1] ?? '')),
                'destination' => $this->normalizeLocation((string) ($matches[2] ?? '')),
            ];
        }

        $found = [];
        foreach ($this->routeValidator->allKnownLocations() as $location) {
            $position = mb_strpos(' '.$this->normalizeText($messageText).' ', ' '.$this->normalizeText($location).' ');
            if ($position !== false) {
                $found[] = ['location' => $location, 'position' => $position];
            }
        }
        usort($found, fn (array $a, array $b): int => $a['position'] <=> $b['position']);
        $ordered = array_values(array_unique(array_map(fn (array $item): string => $item['location'], $found)));

        if (count($ordered) >= 2) {
            return [
                'pickup_location' => $ordered[0],
                'destination' => $ordered[1],
            ];
        }

        if (count($ordered) === 1) {
            $single = $ordered[0];
            $normalized = $this->normalizeText($messageText);
            $singleKey = $this->normalizeText($single);

            if (preg_match('/\bke\s+'.preg_quote($singleKey, '/').'\b/u', $normalized)) {
                return ['pickup_location' => null, 'destination' => $single];
            }

            if (preg_match('/\b(dari|jemput di|asal)\s+'.preg_quote($singleKey, '/').'\b/u', $normalized)) {
                return ['pickup_location' => $single, 'destination' => null];
            }
        }

        return ['pickup_location' => null, 'destination' => null];
    }

    private function splitNames(string $value, int $expectedCount): array
    {
        $parts = preg_split('/[\n,;\/]+/u', $value) ?: [$value];
        $names = [];

        foreach ($parts as $part) {
            $name = $this->normalizeName((string) $part);
            if ($name !== null) {
                $names[] = $name;
            }
        }

        return array_slice(array_values(array_unique($names)), 0, $expectedCount);
    }

    private function normalizeName(string $value): ?string
    {
        $clean = trim(preg_replace('/^(?:nama(?:\s+saya)?|nama(?:\s+penumpang)?(?:nya)?|atas\s+nama|a\/n|saya|sy|aku)\s*/ui', '', $value) ?? $value, " \t\n\r\0\x0B,.;:-");
        $clean = preg_replace('/^nya\s+/ui', '', $clean) ?? $clean;
        $clean = preg_replace('/\b(?:jumlah|tanggal|jam|alamat|metode|bayar)\b.*$/ui', '', $clean) ?? $clean;
        $clean = trim($clean, " \t\n\r\0\x0B,.;:-");

        if ($clean === '' || preg_match('/\d/u', $clean)) {
            return null;
        }

        return mb_convert_case($clean, MB_CASE_TITLE, 'UTF-8');
    }

    private function normalizeAddress(string $value): ?string
    {
        $clean = trim(preg_replace('/\s+/u', ' ', $value) ?? $value, " \t\n\r\0\x0B,;");

        return mb_strlen($clean) >= 5 ? $clean : null;
    }

    private function menuLocation(string $normalizedText): ?string
    {
        return ctype_digit($normalizedText)
            ? $this->routeValidator->menuLocationByOrder((int) $normalizedText)
            : null;
    }

    private function normalizeLocation(?string $value): ?string
    {
        return blank($value) ? null : $this->routeValidator->normalizeLocation((string) $value);
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
            foreach (array_merge([(string) ($method['id'] ?? '')], (array) ($method['aliases'] ?? [])) as $alias) {
                if ($normalized === $this->normalizeText((string) $alias)) {
                    return (string) $method['id'];
                }
            }
        }

        return null;
    }

    private function isGreetingOnly(string $messageText): bool
    {
        return $this->greetingDetector->inspect($messageText)['greeting_only'];
    }

    private function departureSlots(): array
    {
        return (array) config('chatbot.jet.departure_slots', []);
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
        return [
            'jan' => 1, 'januari' => 1, 'feb' => 2, 'februari' => 2, 'mar' => 3, 'maret' => 3,
            'apr' => 4, 'april' => 4, 'mei' => 5, 'jun' => 6, 'juni' => 6, 'jul' => 7, 'juli' => 7,
            'agu' => 8, 'agustus' => 8, 'sep' => 9, 'september' => 9, 'okt' => 10, 'oktober' => 10,
            'nov' => 11, 'november' => 11, 'des' => 12, 'desember' => 12,
        ][$this->normalizeText($month)] ?? null;
    }

    private function normalizeText(string $value): string
    {
        $normalized = mb_strtolower(trim($value), 'UTF-8');
        $normalized = str_replace(["\u{2019}", "'"], '', $normalized);
        $normalized = preg_replace('/[^\p{L}\p{N}\s:\/.,-]/u', ' ', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+/u', ' ', trim($normalized)) ?? trim($normalized);

        return $normalized;
    }

    private function timezone(): string
    {
        return (string) config('chatbot.jet.timezone', 'Asia/Jakarta');
    }
}
