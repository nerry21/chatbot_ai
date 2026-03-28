<?php

namespace App\Services\Chatbot\Guardrails;

use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Services\Chatbot\ConversationStateService;
use Illuminate\Support\Carbon;

class ReplyLoopGuardService
{
    private const RECENT_REPLY_IDENTITY_KEY = 'recent_bot_reply_identity';

    private const RECENT_REPLY_TTL_MINUTES = 10;

    public function __construct(
        private readonly ConversationStateService $stateService,
    ) {
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
        ?string $inboundContextFingerprint = null,
    ): bool {
        $replyIdentity ??= $this->buildReplyIdentity($conversation, $reply, $inboundContextFingerprint);
        $inboundContextFingerprint ??= $this->normalizeText($replyIdentity['inbound_context_fingerprint'] ?? null);

        if ($this->hasInboundContextChanged($conversation, $latestOutbound, $inboundContextFingerprint)) {
            return false;
        }

        $candidateText = (string) ($reply['text'] ?? '');

        if ($latestOutbound !== null && filled($latestOutbound->message_text) && filled($candidateText)) {
            if ($this->normalizeComparableText($latestOutbound->message_text) === $this->normalizeComparableText($candidateText)) {
                return true;
            }
        }

        $replyIdentity ??= $this->buildReplyIdentity($conversation, $reply, $inboundContextFingerprint);
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
     *     action: string|null,
     *     inbound_context_fingerprint: string|null
     * }
     */
    public function buildReplyIdentity(
        Conversation $conversation,
        array $reply,
        ?string $inboundContextFingerprint = null,
    ): array {
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
            'inbound_context_fingerprint' => $this->normalizeText($inboundContextFingerprint),
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

    /**
     * @param  array<string, mixed>  $intentResult
     * @param  array<string, mixed>  $entityResult
     * @param  array<string, mixed>  $resolvedContext
     */
    public function buildInboundContextFingerprint(
        string $messageText,
        array $intentResult = [],
        array $entityResult = [],
        array $resolvedContext = [],
    ): string {
        return sha1((string) json_encode([
            'message' => $this->normalizeComparableText($messageText),
            'intent' => trim((string) ($intentResult['intent'] ?? '')),
            'uses_previous_context' => (bool) ($intentResult['uses_previous_context'] ?? false),
            'needs_clarification' => (bool) ($intentResult['needs_clarification'] ?? false),
            'entities' => array_filter([
                'pickup_location' => $this->normalizeText($entityResult['pickup_location'] ?? null),
                'destination' => $this->normalizeText($entityResult['destination'] ?? null),
                'departure_date' => $this->normalizeText($entityResult['departure_date'] ?? null),
                'departure_time' => $this->normalizeText($entityResult['departure_time'] ?? null),
                'passenger_count' => $entityResult['passenger_count'] ?? null,
                'selected_seats' => is_array($entityResult['selected_seats'] ?? null)
                    ? array_values($entityResult['selected_seats'])
                    : [],
            ], static fn (mixed $value): bool => $value !== null && $value !== [] && $value !== ''),
            'resolved_context' => array_filter([
                'last_origin' => $this->normalizeText($resolvedContext['last_origin'] ?? null),
                'last_destination' => $this->normalizeText($resolvedContext['last_destination'] ?? null),
                'last_travel_date' => $this->normalizeText($resolvedContext['last_travel_date'] ?? null),
                'last_departure_time' => $this->normalizeText($resolvedContext['last_departure_time'] ?? null),
                'current_topic' => $this->normalizeText($resolvedContext['current_topic'] ?? null),
            ], static fn (mixed $value): bool => $value !== null && $value !== ''),
        ]));
    }

    /**
     * @param  array{text?: string, is_fallback?: bool, meta?: array<string, mixed>, message_type?: string, outbound_payload?: array<string, mixed>}  $reply
     */
    public function shouldRewriteRepeatedStatePrompt(
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

    public function normalizeComparableText(?string $text): string
    {
        $value = mb_strtolower(trim((string) $text), 'UTF-8');
        $value = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);

        return $value;
    }

    /**
     * @param  array{text?: string, is_fallback?: bool, meta?: array<string, mixed>, message_type?: string, outbound_payload?: array<string, mixed>}  $reply
     * @return array{text: string, is_fallback: bool, meta: array<string, mixed>, message_type: string, outbound_payload: array<string, mixed>}
     */
    public function buildStateReminderReply(Conversation $conversation, array $reply): array
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

    private function hasInboundContextChanged(
        Conversation $conversation,
        ?ConversationMessage $latestOutbound,
        ?string $candidateInboundContextFingerprint,
    ): bool {
        if ($candidateInboundContextFingerprint === null || $candidateInboundContextFingerprint === '') {
            return false;
        }

        $latestFingerprint = is_array($latestOutbound?->raw_payload)
            ? $this->normalizeText($latestOutbound->raw_payload['inbound_context_fingerprint'] ?? null)
            : null;

        if ($latestFingerprint !== null) {
            return ! hash_equals($latestFingerprint, $candidateInboundContextFingerprint);
        }

        $recentIdentity = $this->recentReplyIdentity($conversation);
        $recentFingerprint = is_array($recentIdentity)
            ? $this->normalizeText($recentIdentity['inbound_context_fingerprint'] ?? null)
            : null;

        return $recentFingerprint !== null
            && ! hash_equals($recentFingerprint, $candidateInboundContextFingerprint);
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
