<?php

namespace App\Services\Chatbot;

use App\Data\Chatbot\ConversationContextMessage;
use App\Models\Conversation;
use App\Services\Booking\RouteValidationService;
use Illuminate\Support\Str;

class ConversationMemoryResolverService
{
    public function __construct(
        private readonly RouteValidationService $routeValidator,
    ) {
    }

    /**
     * @param  array<int, ConversationContextMessage>  $historyMessages
     * @param  array<string, mixed>  $conversationState
     * @param  array<string, mixed>  $knownEntities
     * @return array<string, mixed>
     */
    public function resolve(
        Conversation $conversation,
        string $latestMessageText,
        array $historyMessages,
        array $conversationState = [],
        array $knownEntities = [],
    ): array {
        $latestText = trim($latestMessageText);
        $lastOrigin = $this->resolveOrigin($historyMessages, $knownEntities, $conversationState);
        $lastDestination = $this->resolveDestination($historyMessages, $knownEntities, $conversationState);
        $lastTravelDate = $this->resolveTravelDate($historyMessages, $knownEntities, $conversationState);
        $lastDepartureTime = $this->resolveDepartureTime($historyMessages, $knownEntities, $conversationState);
        $activeIntent = $this->resolveActiveIntent($conversation, $latestText, $conversationState);
        $currentTopic = $this->resolveCurrentTopic($activeIntent, $latestText, $conversationState);
        $contextDependencyDetected = $this->detectContextDependency(
            latestMessageText: $latestText,
            lastOrigin: $lastOrigin,
            lastDestination: $lastDestination,
            lastTravelDate: $lastTravelDate,
            lastDepartureTime: $lastDepartureTime,
        );

        return array_filter([
            'active_intent' => $activeIntent,
            'current_topic' => $currentTopic,
            'booking_state' => $this->normalizeText($conversationState['booking_intent_status'] ?? null),
            'expected_input' => $this->normalizeText($conversationState['booking_expected_input'] ?? null),
            'last_origin' => $lastOrigin,
            'last_destination' => $lastDestination,
            'last_travel_date' => $lastTravelDate,
            'last_departure_time' => $lastDepartureTime,
            'context_dependency_detected' => $contextDependencyDetected,
            'admin_takeover' => $conversation->isAdminTakeover(),
        ], static fn (mixed $value, string $key): bool => match ($key) {
            'context_dependency_detected', 'admin_takeover' => true,
            default => $value !== null && $value !== '',
        }, ARRAY_FILTER_USE_BOTH);
    }

    /**
     * @param  array<int, ConversationContextMessage>  $historyMessages
     * @param  array<string, mixed>  $knownEntities
     * @param  array<string, mixed>  $conversationState
     */
    private function resolveOrigin(array $historyMessages, array $knownEntities, array $conversationState): ?string
    {
        $origin = $this->normalizeLocation(
            $knownEntities['origin']
                ?? $conversationState['pickup_location']
                ?? null,
        );

        if ($origin !== null) {
            return $origin;
        }

        foreach (array_reverse($historyMessages) as $message) {
            if ($message->role !== 'user') {
                continue;
            }

            $location = $this->extractLocationAfterKeyword($message->text, ['dari', 'jemput', 'pickup']);
            if ($location !== null) {
                return $location;
            }
        }

        return null;
    }

    /**
     * @param  array<int, ConversationContextMessage>  $historyMessages
     * @param  array<string, mixed>  $knownEntities
     * @param  array<string, mixed>  $conversationState
     */
    private function resolveDestination(array $historyMessages, array $knownEntities, array $conversationState): ?string
    {
        $destination = $this->normalizeLocation(
            $knownEntities['destination']
                ?? $conversationState['destination']
                ?? null,
        );

        if ($destination !== null) {
            return $destination;
        }

        foreach (array_reverse($historyMessages) as $message) {
            if ($message->role !== 'user') {
                continue;
            }

            $location = $this->extractLocationAfterKeyword($message->text, ['ke', 'tujuan', 'destinasi']);
            if ($location !== null) {
                return $location;
            }
        }

        return null;
    }

    /**
     * @param  array<int, ConversationContextMessage>  $historyMessages
     * @param  array<string, mixed>  $knownEntities
     * @param  array<string, mixed>  $conversationState
     */
    private function resolveTravelDate(array $historyMessages, array $knownEntities, array $conversationState): ?string
    {
        $date = $this->normalizeDate(
            $knownEntities['travel_date']
                ?? $conversationState['travel_date']
                ?? null,
        );

        if ($date !== null) {
            return $date;
        }

        foreach (array_reverse($historyMessages) as $message) {
            if ($message->role !== 'user') {
                continue;
            }

            $parsed = $this->extractExplicitDate($message->text);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        return null;
    }

    /**
     * @param  array<int, ConversationContextMessage>  $historyMessages
     * @param  array<string, mixed>  $knownEntities
     * @param  array<string, mixed>  $conversationState
     */
    private function resolveDepartureTime(array $historyMessages, array $knownEntities, array $conversationState): ?string
    {
        $time = $this->normalizeTime(
            $knownEntities['departure_time']
                ?? $conversationState['travel_time']
                ?? null,
        );

        if ($time !== null) {
            return $time;
        }

        foreach (array_reverse($historyMessages) as $message) {
            if ($message->role !== 'user') {
                continue;
            }

            $parsed = $this->extractTime($message->text);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $conversationState
     */
    private function resolveActiveIntent(
        Conversation $conversation,
        string $latestMessageText,
        array $conversationState,
    ): string {
        $currentIntent = $this->normalizeText($conversation->current_intent);

        if ($currentIntent !== null) {
            return $currentIntent;
        }

        $bookingState = $this->normalizeText($conversationState['booking_intent_status'] ?? null);
        if ($bookingState !== null && $bookingState !== 'idle') {
            return 'booking';
        }

        return $this->inferIntentFromText($latestMessageText);
    }

    /**
     * @param  array<string, mixed>  $conversationState
     */
    private function resolveCurrentTopic(
        string $activeIntent,
        string $latestMessageText,
        array $conversationState,
    ): string {
        if (str_contains($activeIntent, 'schedule') || str_contains($activeIntent, 'jam')) {
            return 'schedule_inquiry';
        }

        if (str_contains($activeIntent, 'price') || str_contains($activeIntent, 'harga')) {
            return 'price_inquiry';
        }

        if (str_contains($activeIntent, 'location') || str_contains($activeIntent, 'rute')) {
            return 'route_inquiry';
        }

        $expectedInput = $this->normalizeText($conversationState['booking_expected_input'] ?? null);
        if ($expectedInput !== null) {
            return 'booking_follow_up';
        }

        return $this->inferIntentFromText($latestMessageText);
    }

    private function detectContextDependency(
        string $latestMessageText,
        ?string $lastOrigin,
        ?string $lastDestination,
        ?string $lastTravelDate,
        ?string $lastDepartureTime,
    ): bool {
        $latestHasLocation = $this->routeValidator->findLocationInText($latestMessageText) !== null;
        $hasPriorContext = $lastOrigin !== null
            || $lastDestination !== null
            || $lastTravelDate !== null
            || $lastDepartureTime !== null;

        if (! $hasPriorContext || $latestHasLocation) {
            return false;
        }

        if (mb_strlen($latestMessageText, 'UTF-8') <= 40) {
            return true;
        }

        return preg_match(
            '/\b(besok|lusa|hari ini|jam|jadwal|ada|bisa|yang|itu|nya|kalau|jadi|berarti|oke)\b/iu',
            $latestMessageText,
        ) === 1;
    }

    /**
     * @param  array<int, string>  $keywords
     */
    private function extractLocationAfterKeyword(string $text, array $keywords): ?string
    {
        foreach ($keywords as $keyword) {
            $pattern = '/\b'.preg_quote($keyword, '/').'\s+(.+?)(?=\s+(jam|pukul|tanggal|tgl|besok|lusa|hari|untuk|naik|berangkat|ada|dong|ya)\b|[,.!?]|$)/iu';

            if (preg_match($pattern, $text, $matches) === 1) {
                $candidate = $this->routeValidator->findLocationInText((string) ($matches[1] ?? ''));
                if ($candidate !== null) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    private function inferIntentFromText(string $text): string
    {
        $normalized = Str::lower(trim($text));

        return match (true) {
            preg_match('/\b(jadwal|jam|berangkat|slot|keberangkatan)\b/u', $normalized) === 1 => 'schedule_inquiry',
            preg_match('/\b(harga|ongkos|tarif|biaya)\b/u', $normalized) === 1 => 'price_inquiry',
            preg_match('/\b(rute|tujuan|jemput|pickup|dari|ke)\b/u', $normalized) === 1 => 'location_inquiry',
            preg_match('/\b(book|booking|pesan|reservasi)\b/u', $normalized) === 1 => 'booking',
            default => 'follow_up',
        };
    }

    private function extractExplicitDate(string $text): ?string
    {
        if (preg_match('/\b(20\d{2}-\d{2}-\d{2})\b/', $text, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    private function extractTime(string $text): ?string
    {
        if (preg_match('/\b(?:jam|pukul)?\s*(\d{1,2})(?:[:.](\d{2}))?\b/iu', $text, $matches) !== 1) {
            return null;
        }

        $hour = (int) ($matches[1] ?? 0);
        $minute = (int) ($matches[2] ?? 0);

        if ($hour < 0 || $hour > 23 || $minute < 0 || $minute > 59) {
            return null;
        }

        return sprintf('%02d:%02d', $hour, $minute);
    }

    private function normalizeLocation(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        return $this->routeValidator->normalizeLocation((string) $value);
    }

    private function normalizeDate(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $normalized) === 1 ? $normalized : null;
    }

    private function normalizeTime(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        if (preg_match('/^(?<hour>[01]?\d|2[0-3])[:.](?<minute>[0-5]\d)$/', $normalized, $matches) === 1) {
            return sprintf('%02d:%02d', (int) $matches['hour'], (int) $matches['minute']);
        }

        return null;
    }

    private function normalizeText(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }
}
