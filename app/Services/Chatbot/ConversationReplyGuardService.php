<?php

namespace App\Services\Chatbot;

use App\Models\BookingRequest;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use Illuminate\Support\Carbon;

class ConversationReplyGuardService
{
    /** @var array<int, string> */
    private const UNAVAILABLE_ACTIONS = [
        'unsupported_route',
        'unavailable',
    ];

    private const RECENT_REPLY_IDENTITY_KEY = 'recent_bot_reply_identity';

    private const RECENT_REPLY_TTL_MINUTES = 10;

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
     * @param  array{text: string, is_fallback: bool, meta: array<string, mixed>, message_type?: string, outbound_payload?: array<string, mixed>}  $reply
     * @return array{
     *     reply: array{text: string, is_fallback: bool, meta: array<string, mixed>, message_type?: string, outbound_payload?: array<string, mixed>},
     *     close_intent_detected: bool,
     *     unavailable_repeat_blocked: bool,
     *     close_conversation: bool,
     *     has_unavailable_context: bool,
     *     has_relevant_booking_update: bool,
     *     state_repeat_rewritten: bool
     * }
     */
    public function guardReply(
        Conversation $conversation,
        string $messageText,
        array $entityResult,
        array $reply,
    ): array {
        $hasUnavailableContext   = $this->hasUnavailableContext($conversation);
        $hasRelevantBookingUpdate = ($reply['meta']['has_booking_update'] ?? false) === true
            || $this->hasRelevantBookingUpdate($entityResult);

        if ($hasUnavailableContext && $this->isCloseIntent($messageText)) {
            return [
                'reply'                     => $this->buildCloseReply(),
                'close_intent_detected'    => true,
                'unavailable_repeat_blocked' => false,
                'close_conversation'       => true,
                'has_unavailable_context'  => true,
                'has_relevant_booking_update' => $hasRelevantBookingUpdate,
                'state_repeat_rewritten'   => false,
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
                'state_repeat_rewritten'   => false,
            ];
        }

        if ($this->shouldRewriteRepeatedStatePrompt($conversation, $reply, $hasRelevantBookingUpdate)) {
            return [
                'reply'                     => $this->buildStateReminderReply($conversation, $reply),
                'close_intent_detected'    => false,
                'unavailable_repeat_blocked' => false,
                'close_conversation'       => false,
                'has_unavailable_context'  => $hasUnavailableContext,
                'has_relevant_booking_update' => $hasRelevantBookingUpdate,
                'state_repeat_rewritten'   => true,
            ];
        }

        return [
            'reply'                     => $reply,
            'close_intent_detected'    => false,
            'unavailable_repeat_blocked' => false,
            'close_conversation'       => false,
            'has_unavailable_context'  => $hasUnavailableContext,
            'has_relevant_booking_update' => $hasRelevantBookingUpdate,
            'state_repeat_rewritten'   => false,
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
     * @param  array{text?: string, is_fallback?: bool, meta?: array<string, mixed>, message_type?: string}  $reply
     * @param  array<string, mixed>|null  $replyIdentity
     */
    public function shouldSkipRepeat(
        Conversation $conversation,
        ?ConversationMessage $latestOutbound,
        array $reply,
        ?array $replyIdentity = null,
    ): bool
    {
        $candidateText = (string) ($reply['text'] ?? '');

        if ($latestOutbound !== null && filled($latestOutbound->message_text) && filled($candidateText)) {
            if ($this->normalizeComparableText($latestOutbound->message_text) === $this->normalizeComparableText($candidateText)) {
                return true;
            }
        }

        $replyIdentity ??= $this->buildReplyIdentity($conversation, $reply);
        $candidateFingerprint = (string) ($replyIdentity['outbound_fingerprint'] ?? '');
        $candidateStateHash = (string) ($replyIdentity['state_response_hash'] ?? '');

        if ($candidateFingerprint === '' && $candidateStateHash === '') {
            return false;
        }

        $latestFingerprint = is_array($latestOutbound?->raw_payload)
            ? (string) ($latestOutbound->raw_payload['outbound_fingerprint'] ?? '')
            : '';
        if ($candidateFingerprint !== '' && $latestFingerprint !== '' && hash_equals($latestFingerprint, $candidateFingerprint)) {
            return true;
        }

        $recentIdentity = $this->recentReplyIdentity($conversation);

        return is_array($recentIdentity)
            && $candidateStateHash !== ''
            && hash_equals((string) ($recentIdentity['state_response_hash'] ?? ''), $candidateStateHash);
    }

    /**
     * @param  array{text?: string, is_fallback?: bool, meta?: array<string, mixed>, message_type?: string}  $reply
     * @return array{
     *     response_hash: string,
     *     outbound_fingerprint: string,
     *     state_response_hash: string,
     *     booking_state: string,
     *     expected_input: string|null,
     *     message_type: string,
     *     action: string|null
     * }
     */
    public function buildReplyIdentity(Conversation $conversation, array $reply): array
    {
        $normalizedText = $this->normalizeComparableText((string) ($reply['text'] ?? ''));
        $messageType = trim((string) ($reply['message_type'] ?? 'text')) ?: 'text';
        $action = is_string($reply['meta']['action'] ?? null) ? trim((string) $reply['meta']['action']) : null;
        $bookingState = trim((string) $this->stateService->get($conversation, 'booking_intent_status', 'idle')) ?: 'idle';
        $expectedInput = $this->stateService->get($conversation, 'booking_expected_input');
        $expectedInput = is_string($expectedInput) && trim($expectedInput) !== '' ? trim($expectedInput) : null;
        $responseHash = sha1($normalizedText);
        $outboundFingerprint = sha1(json_encode([
            'text' => $normalizedText,
            'message_type' => $messageType,
            'action' => $action,
        ]));
        $stateResponseHash = sha1(json_encode([
            'booking_state' => $bookingState,
            'expected_input' => $expectedInput,
            'response_hash' => $responseHash,
        ]));

        return [
            'response_hash' => $responseHash,
            'outbound_fingerprint' => $outboundFingerprint,
            'state_response_hash' => $stateResponseHash,
            'booking_state' => $bookingState,
            'expected_input' => $expectedInput,
            'message_type' => $messageType,
            'action' => $action,
        ];
    }

    /**
     * @param  array<string, mixed>  $identity
     */
    public function rememberReplyIdentity(Conversation $conversation, array $identity): void
    {
        $this->stateService->put(
            conversation: $conversation,
            key: self::RECENT_REPLY_IDENTITY_KEY,
            value: $identity,
            expiresAt: Carbon::now()->addMinutes(self::RECENT_REPLY_TTL_MINUTES),
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function recentReplyIdentity(Conversation $conversation): ?array
    {
        $identity = $this->stateService->get($conversation, self::RECENT_REPLY_IDENTITY_KEY);

        return is_array($identity) ? $identity : null;
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
     * @param  array{text?: string, is_fallback?: bool, meta?: array<string, mixed>, message_type?: string, outbound_payload?: array<string, mixed>}  $reply
     */
    private function shouldRewriteRepeatedStatePrompt(
        Conversation $conversation,
        array $reply,
        bool $hasRelevantBookingUpdate,
    ): bool {
        if ($hasRelevantBookingUpdate) {
            return false;
        }

        if (($reply['meta']['source'] ?? null) !== 'booking_engine') {
            return false;
        }

        $action = (string) ($reply['meta']['action'] ?? '');
        if ($action === '' || (! str_starts_with($action, 'collect_') && $action !== 'ask_confirmation')) {
            return false;
        }

        $identity = $this->buildReplyIdentity($conversation, $reply);
        $recentIdentity = $this->recentReplyIdentity($conversation);
        if (! is_array($recentIdentity) || $recentIdentity === []) {
            return false;
        }

        $expectedInput = $identity['expected_input'] ?? null;
        if (! is_string($expectedInput) || $expectedInput === '') {
            return false;
        }

        $bookingState = (string) ($identity['booking_state'] ?? 'idle');
        if (in_array($bookingState, ['idle', 'waiting_admin_takeover', 'completed'], true)) {
            return false;
        }

        return (string) ($recentIdentity['booking_state'] ?? '') === $bookingState
            && (string) ($recentIdentity['expected_input'] ?? '') === $expectedInput;
    }

    /**
     * @param  array{text?: string, is_fallback?: bool, meta?: array<string, mixed>, message_type?: string, outbound_payload?: array<string, mixed>}  $reply
     * @return array{text: string, is_fallback: bool, meta: array<string, mixed>, message_type: string, outbound_payload: array<string, mixed>}
     */
    private function buildStateReminderReply(Conversation $conversation, array $reply): array
    {
        $expectedInput = $this->stateService->get($conversation, 'booking_expected_input');
        $expectedInput = is_string($expectedInput) ? trim($expectedInput) : '';

        $texts = [
            'passenger_count' => 'Baik Bapak/Ibu, kami tunggu jumlah penumpangnya ya.',
            'travel_date' => 'Baik Bapak/Ibu, kami tunggu tanggal dan jam keberangkatannya ya.',
            'travel_time' => 'Baik Bapak/Ibu, mohon pilih jam keberangkatannya ya.',
            'selected_seats' => 'Baik Bapak/Ibu, kami tunggu pilihan seat-nya ya.',
            'pickup_location' => 'Baik Bapak/Ibu, kami tunggu lokasi jemputnya ya.',
            'pickup_full_address' => 'Baik Bapak/Ibu, kami tunggu alamat jemput lengkapnya ya.',
            'destination' => 'Baik Bapak/Ibu, kami tunggu tujuan pengantarannya ya.',
            'passenger_name' => 'Baik Bapak/Ibu, kami tunggu nama penumpangnya ya.',
            'contact_number' => 'Baik Bapak/Ibu, kami tunggu nomor kontak penumpangnya ya.',
        ];

        return [
            'text' => $texts[$expectedInput] ?? 'Baik Bapak/Ibu, kami tunggu detail berikutnya ya.',
            'is_fallback' => false,
            'message_type' => 'text',
            'outbound_payload' => [],
            'meta' => [
                'source' => 'guard.state_repeat',
                'action' => 'short_pending_reminder',
                'original_source' => $reply['meta']['source'] ?? null,
                'original_action' => $reply['meta']['action'] ?? null,
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
