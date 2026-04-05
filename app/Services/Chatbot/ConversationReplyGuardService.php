<?php

namespace App\Services\Chatbot;

use App\Models\BookingRequest;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Services\Chatbot\Guardrails\ReplyLoopGuardService;
use App\Services\Chatbot\Guardrails\UnavailableReplyGuardService;

class ConversationReplyGuardService
{
    public function __construct(
        private readonly UnavailableReplyGuardService $unavailableGuard,
        private readonly ReplyLoopGuardService $replyLoopGuard,
    ) {
    }

    /**
     * @param  array<string, mixed>  $entityResult
     * @param  array<string, mixed>  $reply
     * @param  array<string, mixed>  $intentResult
     * @param  array<string, mixed>  $guardContext
     * @return array{
     *     reply: array<string, mixed>,
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
        array $intentResult = [],
        array $guardContext = [],
    ): array {
        $result = $this->unavailableGuard->apply(
            conversation: $conversation,
            messageText: $messageText,
            entityResult: $entityResult,
            reply: $reply,
        );

        if ($result['close_intent_detected'] || $result['unavailable_repeat_blocked']) {
            $result['state_repeat_rewritten'] = false;

            return $result;
        }

        if ($this->replyLoopGuard->shouldRewriteRepeatedStatePrompt(
            conversation: $conversation,
            reply: $result['reply'],
            hasRelevantBookingUpdate: (bool) ($result['has_relevant_booking_update'] ?? false),
        )) {
            return [
                ...$result,
                'reply' => $this->replyLoopGuard->buildStateReminderReply($conversation, $result['reply']),
                'state_repeat_rewritten' => true,
            ];
        }

        return [
            ...$result,
            'state_repeat_rewritten' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $replyResult
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $orchestrationSnapshot
     * @return array<string, mixed>
     */
    public function guardConversationReply(
        array $replyResult,
        array $context,
        array $orchestrationSnapshot = [],
    ): array {
        $crm = is_array($context['crm_context'] ?? null) ? $context['crm_context'] : [];
        $conversation = is_array($crm['conversation'] ?? null) ? $crm['conversation'] : [];
        $booking = is_array($crm['booking'] ?? null) ? $crm['booking'] : [];

        $reply = trim((string) ($replyResult['reply'] ?? $replyResult['text'] ?? ''));
        $nextAction = (string) ($replyResult['next_action'] ?? 'answer_question');
        $notes = is_array($replyResult['safety_notes'] ?? null) ? $replyResult['safety_notes'] : [];

        if ($reply === '') {
            $reply = 'Baik, saya bantu dulu ya. Mohon jelaskan sedikit lebih detail agar saya bisa menindaklanjuti dengan tepat.';
            $notes[] = 'Conversation guard filled empty reply';
        }

        if (mb_strlen($reply, 'UTF-8') > 1200) {
            $reply = mb_substr($reply, 0, 1200, 'UTF-8');
            $notes[] = 'Conversation guard trimmed long reply';
        }

        if (! empty($booking['missing_fields']) && is_array($booking['missing_fields'])) {
            if ($nextAction === 'answer_question') {
                $replyResult['next_action'] = 'ask_missing_data';
                $replyResult['data_requests'] = array_values($booking['missing_fields']);
                $notes[] = 'Conversation guard aligned reply with booking missing fields';
            }
        }

        $replyResult['meta'] = is_array($replyResult['meta'] ?? null) ? $replyResult['meta'] : [];

        if (($conversation['needs_human'] ?? false) === true) {
            $replyResult['should_escalate'] = true;
            $replyResult['handoff_reason'] = $replyResult['handoff_reason'] ?? 'Conversation requires human follow-up';
            $replyResult['meta']['force_handoff'] = true;
            $notes[] = 'Conversation guard enforced human follow-up';
        }

        if (($orchestrationSnapshot['reply_force_handoff'] ?? false) === true) {
            $replyResult['should_escalate'] = true;
            $replyResult['meta']['force_handoff'] = true;
            $notes[] = 'Conversation guard respected orchestration handoff';
        }

        $replyResult['reply'] = $reply;
        $replyResult['text'] = $reply;
        $replyResult['safety_notes'] = array_values(array_unique(array_filter($notes)));

        $existingMeta = is_array($replyResult['meta'] ?? null) ? $replyResult['meta'] : [];
        $replyResult['meta'] = array_merge($existingMeta, [
            'conversation_guard_applied' => true,
            'decision_trace' => array_values(array_filter([
                ...(is_array($existingMeta['decision_trace'] ?? null) ? $existingMeta['decision_trace'] : []),
                [
                    'stage' => 'conversation_reply_guard',
                    'action' => ($replyResult['should_escalate'] ?? false) ? 'handoff_or_safe_reply' : 'allow',
                    'blocked' => false,
                    'notes' => array_values(array_unique(array_filter($notes))),
                    'evaluated_at' => now()->toIso8601String(),
                ],
            ])),
        ]);

        return $replyResult;
    }

    /**
     * @param  array<string, mixed>  $reply
     */
    public function rememberUnavailableContext(
        Conversation $conversation,
        ?BookingRequest $booking,
        array $reply,
    ): void {
        $this->unavailableGuard->rememberUnavailableContext($conversation, $booking, $reply);
    }

    public function clearUnavailableContext(Conversation $conversation): void
    {
        $this->unavailableGuard->clearUnavailableContext($conversation);
    }

    public function hasUnavailableContext(Conversation $conversation): bool
    {
        return $this->unavailableGuard->hasUnavailableContext($conversation);
    }

    /**
     * @param  array<string, mixed>  $reply
     */
    public function isUnavailableReply(array $reply): bool
    {
        return $this->unavailableGuard->isUnavailableReply($reply);
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
        return $this->replyLoopGuard->shouldSkipRepeat(
            conversation: $conversation,
            latestOutbound: $latestOutbound,
            reply: $reply,
            replyIdentity: $replyIdentity,
            inboundContextFingerprint: $inboundContextFingerprint,
        );
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
        return $this->replyLoopGuard->buildReplyIdentity($conversation, $reply, $inboundContextFingerprint);
    }

    /**
     * @param  array<string, mixed>  $identity
     */
    public function rememberReplyIdentity(Conversation $conversation, array $identity): void
    {
        $this->replyLoopGuard->rememberReplyIdentity($conversation, $identity);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function recentReplyIdentity(Conversation $conversation): ?array
    {
        return $this->replyLoopGuard->recentReplyIdentity($conversation);
    }

    public function isCloseIntent(string $messageText): bool
    {
        return $this->unavailableGuard->isCloseIntent($messageText);
    }

    public function normalizeComparableText(?string $text): string
    {
        return $this->replyLoopGuard->normalizeComparableText($text);
    }

    /**
     * @param  array<string, mixed>  $entityResult
     */
    public function hasRelevantBookingUpdate(array $entityResult): bool
    {
        return $this->unavailableGuard->hasRelevantBookingUpdate($entityResult);
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
        return $this->replyLoopGuard->buildInboundContextFingerprint(
            messageText: $messageText,
            intentResult: $intentResult,
            entityResult: $entityResult,
            resolvedContext: $resolvedContext,
        );
    }
}
