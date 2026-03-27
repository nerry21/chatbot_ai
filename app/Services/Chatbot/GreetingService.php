<?php

namespace App\Services\Chatbot;

use App\Models\Conversation;
use App\Support\WaLog;
use App\Services\Booking\TimeGreetingService;

class GreetingService
{
    /**
     * @var array<int, string>
     */
    private const STRONG_CONTEXT_KEYS = [
        'booking_intent_status',
        'booking_expected_input',
        'waiting_for',
        'waiting_reason',
        'pickup_point',
        'destination_point',
        'travel_date',
        'travel_time',
        'passenger_count',
        'selected_seats',
        'route_unavailable_context',
    ];

    public function __construct(
        private readonly TimeGreetingService $timeGreetingService,
    ) {
    }

    /**
     * @return array{
     *     has_islamic_greeting: bool,
     *     has_general_greeting: bool,
     *     greeting_only: bool,
     *     time_greeting: array{key: string, label: string, opening: string}
     * }
     */
    public function inspect(string $messageText): array
    {
        $normalized = $this->normalize($messageText);
        $hasIslamicGreeting = (bool) preg_match(
            '/\b(assalamualaikum|assalamu alaikum|ass wr wb|ass wr\. wb|salam)\b/u',
            $normalized,
        );

        $hasGeneralGreeting = $hasIslamicGreeting
            || (bool) preg_match(
                '/\b(halo|hai|hello|selamat pagi|selamat siang|selamat sore|selamat malam|pagi|siang|sore|malam)\b/u',
                $normalized,
            );

        $greetingOnly = $hasGeneralGreeting
            && ! preg_match('/\b(harga|ongkos|jadwal|pesan|booking|berangkat|keberangkatan|jemput|antar|seat|kursi|rute|mobil)\b/u', $normalized);

        return [
            'has_islamic_greeting' => $hasIslamicGreeting,
            'has_general_greeting' => $hasGeneralGreeting,
            'greeting_only'        => $greetingOnly,
            'time_greeting'        => $this->timeGreetingService->resolve(),
        ];
    }

    /**
     * @param  array<string, mixed>  $activeStates
     */
    public function shouldUseOpeningGreeting(Conversation $conversation, array $activeStates = []): bool
    {
        $inboundCount = $conversation->messages()->inbound()->count();

        if ($inboundCount <= 1) {
            return true;
        }

        return ! $this->hasStrongContext($conversation, $activeStates);
    }

    /**
     * @param  array<string, mixed>  $activeStates
     */
    public function buildOpeningGreeting(
        Conversation $conversation,
        string $messageText,
        array $activeStates = [],
    ): ?string {
        $inspection = $this->inspect($messageText);

        if (! $inspection['has_general_greeting']) {
            return null;
        }

        if (! $this->shouldUseOpeningGreeting($conversation, $activeStates)) {
            WaLog::debug('[Greeting] Opening greeting suppressed because context already exists', [
                'conversation_id' => $conversation->id,
                'current_intent'  => $conversation->current_intent,
                'has_summary'     => filled($conversation->summary),
            ]);

            return null;
        }

        $text = $inspection['time_greeting']['opening'];

        if ($inspection['has_islamic_greeting']) {
            $text = "Waalaikumsalam Warahmatullahi Wabarakatuh\n\n" . $text;
        }

        WaLog::info('[Greeting] Opening greeting emitted', [
            'conversation_id'       => $conversation->id,
            'has_islamic_greeting'  => $inspection['has_islamic_greeting'],
            'time_greeting'         => $inspection['time_greeting']['key'],
        ]);

        return $text;
    }

    public function prependIslamicGreeting(string $messageText, string $replyText): string
    {
        $inspection = $this->inspect($messageText);

        if (! $inspection['has_islamic_greeting']) {
            return $replyText;
        }

        $normalizedReply = $this->normalize($replyText);

        if (str_starts_with($normalizedReply, 'waalaikumsalam warahmatullahi wabarakatuh')) {
            return $replyText;
        }

        return "Waalaikumsalam Warahmatullahi Wabarakatuh\n\n" . $replyText;
    }

    private function hasStrongContext(Conversation $conversation, array $activeStates): bool
    {
        if (filled($conversation->summary)) {
            return true;
        }

        if (
            filled($conversation->current_intent)
            && ! in_array($conversation->current_intent, ['greeting', 'farewell', 'unknown'], true)
        ) {
            return true;
        }

        foreach (self::STRONG_CONTEXT_KEYS as $key) {
            $value = $activeStates[$key] ?? null;

            if ($key === 'booking_intent_status' && in_array($value, [null, '', 'idle'], true)) {
                continue;
            }

            if ($this->hasMeaningfulValue($value)) {
                return true;
            }
        }

        return false;
    }

    private function hasMeaningfulValue(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        if (is_string($value)) {
            return trim($value) !== '';
        }

        if (is_array($value)) {
            return $value !== [];
        }

        return true;
    }

    private function normalize(string $text): string
    {
        $normalized = mb_strtolower(trim($text), 'UTF-8');
        $normalized = str_replace(['’', "'"], '', $normalized);
        $normalized = preg_replace('/[^\p{L}\p{N}\s.]/u', ' ', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+/u', ' ', trim($normalized)) ?? trim($normalized);

        return $normalized;
    }
}
