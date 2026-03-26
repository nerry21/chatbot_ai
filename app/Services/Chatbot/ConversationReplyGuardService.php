<?php

namespace App\Services\Chatbot;

use App\Models\BookingRequest;
use App\Models\Conversation;
use App\Models\ConversationMessage;

class ConversationReplyGuardService
{
    /** @var array<int, string> */
    private const UNAVAILABLE_ACTIONS = [
        'unsupported_route',
        'unavailable',
    ];

    /** @var array<int, string> */
    private const RELEVANT_ENTITY_KEYS = [
        'pickup_location',
        'destination',
        'departure_date',
        'departure_time',
        'passenger_count',
    ];

    public function __construct(
        private readonly ConversationStateService $stateService,
    ) {
    }

    /**
     * @param  array<string, mixed>  $entityResult
     * @param  array{text: string, is_fallback: bool, meta: array<string, mixed>}  $reply
     * @return array{
     *     reply: array{text: string, is_fallback: bool, meta: array<string, mixed>},
     *     close_intent_detected: bool,
     *     unavailable_repeat_blocked: bool,
     *     close_conversation: bool,
     *     has_unavailable_context: bool,
     *     has_relevant_booking_update: bool
     * }
     */
    public function guardReply(
        Conversation $conversation,
        string $messageText,
        array $entityResult,
        array $reply,
    ): array {
        $hasUnavailableContext   = $this->hasUnavailableContext($conversation);
        $hasRelevantBookingUpdate = $this->hasRelevantBookingUpdate($entityResult);

        if ($hasUnavailableContext && $this->isCloseIntent($messageText)) {
            return [
                'reply'                     => $this->buildCloseReply(),
                'close_intent_detected'    => true,
                'unavailable_repeat_blocked' => false,
                'close_conversation'       => true,
                'has_unavailable_context'  => true,
                'has_relevant_booking_update' => $hasRelevantBookingUpdate,
            ];
        }

        if (
            $hasUnavailableContext
            && ! $hasRelevantBookingUpdate
            && $this->isUnavailableReply($reply)
        ) {
            return [
                'reply'                     => $this->buildUnavailableFollowUpReply(),
                'close_intent_detected'    => false,
                'unavailable_repeat_blocked' => true,
                'close_conversation'       => false,
                'has_unavailable_context'  => true,
                'has_relevant_booking_update' => false,
            ];
        }

        return [
            'reply'                     => $reply,
            'close_intent_detected'    => false,
            'unavailable_repeat_blocked' => false,
            'close_conversation'       => false,
            'has_unavailable_context'  => $hasUnavailableContext,
            'has_relevant_booking_update' => $hasRelevantBookingUpdate,
        ];
    }

    /**
     * @param  array<string, mixed>  $reply
     */
    public function rememberUnavailableContext(
        Conversation $conversation,
        ?BookingRequest $booking,
        array $reply,
    ): void {
        if (! $this->isUnavailableReply($reply)) {
            return;
        }

        $this->stateService->put(
            conversation: $conversation,
            key: $this->unavailableStateKey(),
            value: [
                'action' => $reply['meta']['action'] ?? null,
                'source' => $reply['meta']['source'] ?? null,
                'text' => $reply['text'] ?? null,
                'pickup_location' => $booking?->pickup_location,
                'destination' => $booking?->destination,
                'departure_date' => $booking?->departure_date?->toDateString(),
                'departure_time' => $booking?->departure_time,
                'recorded_at' => now()->toIso8601String(),
            ],
            expiresAt: now()->addHours($this->unavailableStateTtlHours()),
        );
    }

    public function clearUnavailableContext(Conversation $conversation): void
    {
        $this->stateService->forget($conversation, $this->unavailableStateKey());
    }

    public function hasUnavailableContext(Conversation $conversation): bool
    {
        $state = $this->stateService->get($conversation, $this->unavailableStateKey());

        return is_array($state) && $state !== [];
    }

    /**
     * @param  array<string, mixed>  $reply
     */
    public function isUnavailableReply(array $reply): bool
    {
        return ($reply['meta']['source'] ?? null) === 'booking_engine'
            && in_array($reply['meta']['action'] ?? null, self::UNAVAILABLE_ACTIONS, true);
    }

    /**
     * Compare two outbound texts after normalization so small punctuation or
     * casing differences do not slip through the anti-repeat guard.
     */
    public function shouldSkipRepeat(?ConversationMessage $latestOutbound, string $candidateText): bool
    {
        if ($latestOutbound === null || blank($latestOutbound->message_text) || blank($candidateText)) {
            return false;
        }

        return $this->normalizeComparableText($latestOutbound->message_text) === $this->normalizeComparableText($candidateText);
    }

    public function isCloseIntent(string $messageText): bool
    {
        $normalized = $this->normalizeComparableText($messageText);

        if ($normalized === '') {
            return false;
        }

        $phrases = $this->closeIntentPhrases();

        if (in_array($normalized, $phrases, true)) {
            return true;
        }

        $trimmed = $this->trimCourtesyTail($normalized);

        return $trimmed !== $normalized
            && in_array($trimmed, $phrases, true);
    }

    public function normalizeComparableText(?string $text): string
    {
        $value = mb_strtolower(trim((string) $text), 'UTF-8');
        $value = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);

        return $value;
    }

    /**
     * @param  array<string, mixed>  $entityResult
     */
    public function hasRelevantBookingUpdate(array $entityResult): bool
    {
        foreach (self::RELEVANT_ENTITY_KEYS as $key) {
            $value = $entityResult[$key] ?? null;

            if ($value === null) {
                continue;
            }

            if (is_string($value) && trim($value) === '') {
                continue;
            }

            if (is_array($value) && $value === []) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * @return array{text: string, is_fallback: bool, meta: array<string, mixed>}
     */
    private function buildCloseReply(): array
    {
        return [
            'text'        => (string) config('chatbot.guards.unavailable_close_reply'),
            'is_fallback' => false,
            'meta'        => [
                'source' => 'guard.close_intent',
                'action' => 'close_after_unavailable',
            ],
        ];
    }

    /**
     * @return array{text: string, is_fallback: bool, meta: array<string, mixed>}
     */
    private function buildUnavailableFollowUpReply(): array
    {
        return [
            'text'        => (string) config('chatbot.guards.unavailable_followup_reply'),
            'is_fallback' => false,
            'meta'        => [
                'source' => 'guard.unavailable_followup',
                'action' => 'request_new_booking_data',
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function closeIntentPhrases(): array
    {
        /** @var array<int, string> $phrases */
        $phrases = config('chatbot.guards.close_intents', []);

        return array_values(array_filter(array_map(
            fn (mixed $phrase) => is_string($phrase) ? $this->normalizeComparableText($phrase) : '',
            $phrases,
        )));
    }

    private function unavailableStateKey(): string
    {
        return (string) config('chatbot.guards.unavailable_state_key', 'route_unavailable_context');
    }

    private function unavailableStateTtlHours(): int
    {
        return max(1, (int) config('chatbot.guards.unavailable_state_ttl_hours', 24));
    }

    private function trimCourtesyTail(string $normalized): string
    {
        $tokens = explode(' ', $normalized);

        /** @var array<int, string> $tails */
        $tails = config('chatbot.guards.close_intent_courtesy_tails', []);
        $tailLookup = array_fill_keys(array_map(
            fn (string $tail) => $this->normalizeComparableText($tail),
            $tails,
        ), true);

        while (! empty($tokens)) {
            $lastToken = $tokens[array_key_last($tokens)];

            if (! isset($tailLookup[$lastToken])) {
                break;
            }

            array_pop($tokens);
        }

        return implode(' ', $tokens);
    }
}
