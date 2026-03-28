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
