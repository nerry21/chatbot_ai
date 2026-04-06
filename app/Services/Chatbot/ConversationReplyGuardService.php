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
     * @param  array<string, mixed>  $intentResult
     * @param  array<string, mixed>  $orchestrationSnapshot
     * @return array{
     *     text: string,
     *     is_fallback: bool,
     *     next_action?: string,
     *     should_escalate?: bool,
     *     handoff_reason?: string|null,
     *     clarification_question?: string|null,
     *     meta: array<string, mixed>
     * }
     */
    public function guardConversationReply(
        array $replyResult,
        array $context,
        array $intentResult = [],
        array $orchestrationSnapshot = [],
    ): array {
        $crm = is_array($context['crm_context'] ?? null) ? $context['crm_context'] : [];
        $booking = is_array($crm['booking'] ?? null) ? $crm['booking'] : [];

        $reply = trim((string) ($replyResult['reply'] ?? $replyResult['text'] ?? ''));
        $notes = is_array($replyResult['safety_notes'] ?? null) ? $replyResult['safety_notes'] : [];

        $llmRuntimeBundle = $this->resolveLlmRuntimeBundle($replyResult, $context);
        $runtimeHealth = $this->resolveRuntimeHealthFromBundle($llmRuntimeBundle);

        $signals = $this->collectFinalGuardSignals(
            context: $context,
            intentResult: $intentResult,
            snapshot: $orchestrationSnapshot,
        );
        $signals['runtime_health'] = $runtimeHealth;

        $finalAction = $this->resolveFinalAction($signals);

        $replyResult['meta'] = is_array($replyResult['meta'] ?? null) ? $replyResult['meta'] : [];
        $replyResult['meta']['llm_runtime'] = $llmRuntimeBundle;
        $replyResult['meta']['runtime_health'] = $runtimeHealth;

        if (! empty($booking['missing_fields']) && is_array($booking['missing_fields'])) {
            if (($replyResult['next_action'] ?? 'answer_question') === 'answer_question' && $finalAction === 'send_reply') {
                $replyResult['next_action'] = 'ask_missing_data';
                $replyResult['data_requests'] = array_values($booking['missing_fields']);
                $notes[] = 'Conversation guard aligned reply with booking missing fields';
            }
        }

        if ($finalAction === 'escalate_to_human') {
            $handoffText = $this->normalizeText($reply) ?? 'Baik, saya teruskan dulu ke admin agar dibantu lebih tepat ya.';
            $reason = $signals['policy_reason']
                ?? $signals['grounding_reason']
                ?? 'Final guard memutuskan eskalasi ke admin.';

            $replyResult['text'] = $handoffText;
            $replyResult['reply'] = $handoffText;
            $replyResult['next_action'] = 'handoff_admin';
            $replyResult['should_escalate'] = true;
            $replyResult['handoff_reason'] = $reason;
            $replyResult['is_fallback'] = (bool) ($replyResult['is_fallback'] ?? false);

            $existingMeta = is_array($replyResult['meta'] ?? null) ? $replyResult['meta'] : [];
            $replyResult['meta'] = array_merge(
                $existingMeta,
                $this->buildFinalGuardMeta(
                    action: 'escalate_to_human',
                    signals: $signals,
                    context: $context,
                    snapshot: $orchestrationSnapshot,
                    reason: $reason,
                ),
            );
            $replyResult['safety_notes'] = array_values(array_unique(array_filter($notes)));

            return $replyResult;
        }

        if ($finalAction === 'ask_clarification') {
            $clarificationText = $signals['clarification_question'] ?? $this->safeFallbackText();
            $reason = $signals['policy_reason']
                ?? $signals['grounding_reason']
                ?? 'Final guard memutuskan klarifikasi tambahan diperlukan.';

            $replyResult['text'] = $clarificationText;
            $replyResult['reply'] = $clarificationText;
            $replyResult['clarification_question'] = $clarificationText;
            $replyResult['next_action'] = 'ask_clarification';
            $replyResult['should_escalate'] = false;
            $replyResult['handoff_reason'] = null;
            $replyResult['is_fallback'] = false;

            $existingMeta = is_array($replyResult['meta'] ?? null) ? $replyResult['meta'] : [];
            $replyResult['meta'] = array_merge(
                $existingMeta,
                $this->buildFinalGuardMeta(
                    action: 'ask_clarification',
                    signals: $signals,
                    context: $context,
                    snapshot: $orchestrationSnapshot,
                    reason: $reason,
                ),
            );
            $replyResult['safety_notes'] = array_values(array_unique(array_filter($notes)));

            return $replyResult;
        }

        if ($this->normalizeText($reply) === null) {
            $replyResult['text'] = $this->safeFallbackText();
            $replyResult['reply'] = $replyResult['text'];
            $replyResult['next_action'] = 'safe_fallback';
            $replyResult['should_escalate'] = false;
            $replyResult['is_fallback'] = true;

            $existingMeta = is_array($replyResult['meta'] ?? null) ? $replyResult['meta'] : [];
            $replyResult['meta'] = array_merge(
                $existingMeta,
                $this->buildFinalGuardMeta(
                    action: 'safe_fallback',
                    signals: $signals,
                    context: $context,
                    snapshot: $orchestrationSnapshot,
                    reason: 'Reply kosong diganti fallback aman.',
                ),
            );
            $replyResult['safety_notes'] = array_values(array_unique(array_filter($notes)));

            return $replyResult;
        }

        $replyResult['text'] = $this->trimReplyIfNeeded($reply);
        $replyResult['reply'] = $replyResult['text'];

        $replyResult['next_action'] = $replyResult['next_action'] ?? 'send_reply';
        $replyResult['should_escalate'] = false;
        $replyResult['handoff_reason'] = null;

        $existingMeta = is_array($replyResult['meta'] ?? null) ? $replyResult['meta'] : [];
        $replyResult['meta'] = array_merge(
            $existingMeta,
            $this->buildFinalGuardMeta(
                action: 'send_reply',
                signals: $signals,
                context: $context,
                snapshot: $orchestrationSnapshot,
                reason: 'Reply lolos final guard.',
            ),
        );
        $replyResult['safety_notes'] = array_values(array_unique(array_filter($notes)));

        return $replyResult;
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $intentResult
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function collectFinalGuardSignals(
        array $context,
        array $intentResult,
        array $snapshot,
    ): array {
        $policy = is_array($context['policy_guard'] ?? null) ? $context['policy_guard'] : [];
        $grounding = is_array($context['hallucination_guard'] ?? null) ? $context['hallucination_guard'] : [];
        $understandingRuntime = is_array($context['understanding_runtime'] ?? null) ? $context['understanding_runtime'] : [];
        $replyOrchestration = is_array($context['reply_orchestration'] ?? null) ? $context['reply_orchestration'] : [];
        $crm = is_array($context['crm_context'] ?? null) ? $context['crm_context'] : [];
        $crmConversation = is_array($crm['conversation'] ?? null) ? $crm['conversation'] : [];

        $policyVerdict = $this->normalizeText(
            $policy['verdict']
                ?? $policy['policy_verdict']
                ?? $intentResult['policy_verdict']
                ?? null
        ) ?? 'allow';

        $groundingVerdict = $this->normalizeText(
            $grounding['verdict']
                ?? $grounding['grounding_verdict']
                ?? null
        ) ?? 'grounded';

        $runtimeHealth = $this->normalizeText(
            $policy['runtime_health']
                ?? $understandingRuntime['status']
                ?? $intentResult['runtime_health']
                ?? null
        ) ?? 'healthy';

        $replyAction = $this->normalizeText(
            $replyOrchestration['reply_guard_action']
                ?? $replyOrchestration['reply_action']
                ?? $snapshot['reply_action']
                ?? null
        );

        return [
            'policy_verdict' => $policyVerdict,
            'grounding_verdict' => $groundingVerdict,
            'runtime_health' => $runtimeHealth,
            'reply_action' => $replyAction,
            'policy_force_handoff' => (bool) ($policy['force_handoff'] ?? false),
            'policy_force_clarification' => (bool) ($policy['force_clarification'] ?? false),
            'grounding_force_handoff' => (bool) ($grounding['force_handoff'] ?? false),
            'grounding_force_clarification' => (bool) ($grounding['force_clarification'] ?? false),
            'orchestration_force_handoff' => (bool) ($replyOrchestration['reply_force_handoff'] ?? false),
            'needs_human' => (bool) (
                ($replyOrchestration['needs_human'] ?? false)
                || ($snapshot['needs_human'] ?? false)
                || ($intentResult['handoff_recommended'] ?? false)
                || ($crmConversation['needs_human'] ?? false)
            ),
            'clarification_question' => $this->normalizeText(
                $intentResult['clarification_question']
                    ?? $replyOrchestration['clarification_question']
                    ?? null
            ),
            'grounding_reason' => $this->normalizeText($grounding['reason'] ?? null),
            'policy_reason' => $this->normalizeText(
                $policy['reason_code']
                    ?? ($policy['reasons'][0] ?? null)
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $signals
     */
    private function resolveFinalAction(array $signals): string
    {
        if (
            ($signals['policy_verdict'] ?? 'allow') === 'blocked'
            || ($signals['policy_verdict'] ?? 'allow') === 'handoff'
            || ($signals['policy_force_handoff'] ?? false) === true
            || ($signals['grounding_force_handoff'] ?? false) === true
            || ($signals['orchestration_force_handoff'] ?? false) === true
            || ($signals['needs_human'] ?? false) === true
            || in_array(($signals['runtime_health'] ?? 'healthy'), ['fallback', 'schema_invalid'], true)
        ) {
            return 'escalate_to_human';
        }

        if (
            ($signals['policy_verdict'] ?? 'allow') === 'clarify'
            || ($signals['grounding_verdict'] ?? 'grounded') === 'needs_clarification'
            || ($signals['grounding_verdict'] ?? 'grounded') === 'partially_grounded'
            || ($signals['policy_force_clarification'] ?? false) === true
            || ($signals['grounding_force_clarification'] ?? false) === true
            || ($signals['reply_action'] ?? null) === 'ask_clarification'
            || ($signals['runtime_health'] ?? 'healthy') === 'degraded'
        ) {
            return 'ask_clarification';
        }

        return 'send_reply';
    }

    private function safeFallbackText(): string
    {
        return 'Baik, agar saya tidak keliru, boleh dijelaskan lagi detail kebutuhan atau pertanyaannya ya?';
    }

    /**
     * @param  array<string, mixed>  $signals
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function buildFinalGuardMeta(
        string $action,
        array $signals,
        array $context,
        array $snapshot,
        ?string $reason = null,
    ): array {
        $traceId = $this->normalizeText(
            $context['job_trace_id']
                ?? $context['trace_id']
                ?? $snapshot['decision_trace_id']
                ?? null
        );

        return [
            'guard_group' => 'conversation_reply',
            'action' => $action,
            'verdict' => $action,
            'reason' => $reason,
            'policy_verdict' => $signals['policy_verdict'] ?? null,
            'grounding_verdict' => $signals['grounding_verdict'] ?? null,
            'runtime_health' => $signals['runtime_health'] ?? null,
            'trace_id' => $traceId,
            'force_handoff' => $action === 'escalate_to_human',
            'force_clarification' => $action === 'ask_clarification',
            'candidate_only' => false,
            'is_final_decider' => true,
            'decision_trace_final_guard' => [
                'trace_id' => $traceId,
                'final_guard' => [
                    'stage' => 'conversation_reply_guard',
                    'action' => $action,
                    'policy_verdict' => $signals['policy_verdict'] ?? null,
                    'grounding_verdict' => $signals['grounding_verdict'] ?? null,
                    'runtime_health' => $signals['runtime_health'] ?? null,
                    'reason' => $reason,
                    'evaluated_at' => now()->toIso8601String(),
                ],
                'outcome' => [
                    'final_action' => $action,
                    'handoff' => $action === 'escalate_to_human',
                    'clarify' => $action === 'ask_clarification',
                    'safe_fallback' => $action === 'safe_fallback',
                    'send_reply' => $action === 'send_reply',
                ],
            ],
        ];
    }

    private function normalizeText(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $normalized = trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');

        return $normalized !== '' ? $normalized : null;
    }

    private function trimReplyIfNeeded(string $text, int $limit = 1500): string
    {
        return mb_strlen($text) > $limit
            ? mb_substr($text, 0, $limit - 3).'...'
            : $text;
    }

    private function resolveTraceId(array ...$sources): string
    {
        foreach ($sources as $source) {
            foreach ([
                $source['trace_id'] ?? null,
                $source['_llm']['trace_id'] ?? null,
                $source['meta']['trace_id'] ?? null,
                $source['decision_trace']['trace_id'] ?? null,
                $source['job_trace_id'] ?? null,
            ] as $candidate) {
                if (is_scalar($candidate) && trim((string) $candidate) !== '') {
                    return trim((string) $candidate);
                }
            }
        }

        return 'trace-'.now()->format('YmdHis').'-'.substr(md5((string) microtime(true)), 0, 8);
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function mergeDecisionTrace(array $base, array $extra): array
    {
        if (array_is_list($base)) {
            $normalized = [];

            foreach ($base as $part) {
                if (is_array($part)) {
                    $normalized = array_replace_recursive($normalized, $part);
                }
            }

            $base = $normalized;
        }

        $merged = array_replace_recursive($base, $extra);

        if (! isset($merged['trace_id']) || ! is_scalar($merged['trace_id']) || trim((string) $merged['trace_id']) === '') {
            $merged['trace_id'] = $this->resolveTraceId($base, $extra);
        }

        return $merged;
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
     * @param  array<string, mixed>  $replyResult
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function resolveLlmRuntimeBundle(array $replyResult = [], array $context = []): array
    {
        $replyMeta = is_array($replyResult['meta'] ?? null) ? $replyResult['meta'] : [];
        $contextRuntime = is_array($context['llm_runtime'] ?? null) ? $context['llm_runtime'] : [];
        $replyRuntime = is_array($replyMeta['llm_runtime'] ?? null) ? $replyMeta['llm_runtime'] : [];

        return [
            'understanding' => is_array($contextRuntime['understanding'] ?? null)
                ? $contextRuntime['understanding']
                : (is_array($context['understanding_runtime'] ?? null) ? $context['understanding_runtime'] : []),
            'reply_draft' => is_array($replyRuntime['reply_draft'] ?? null)
                ? $replyRuntime['reply_draft']
                : (is_array($contextRuntime['reply_draft'] ?? null) ? $contextRuntime['reply_draft'] : []),
            'grounded_response' => is_array($replyRuntime['grounded_response'] ?? null)
                ? $replyRuntime['grounded_response']
                : (is_array($contextRuntime['grounded_response'] ?? null) ? $contextRuntime['grounded_response'] : []),
        ];
    }

    /**
     * @param  array<string, mixed>  $bundle
     */
    private function resolveRuntimeHealthFromBundle(array $bundle): string
    {
        foreach (['understanding', 'reply_draft', 'grounded_response'] as $stage) {
            $runtime = is_array($bundle[$stage] ?? null) ? $bundle[$stage] : [];

            if (($runtime['status'] ?? null) === 'fallback') {
                return 'fallback';
            }
        }

        foreach (['understanding', 'reply_draft', 'grounded_response'] as $stage) {
            $runtime = is_array($bundle[$stage] ?? null) ? $bundle[$stage] : [];

            if (array_key_exists('schema_valid', $runtime) && ($runtime['schema_valid'] ?? true) === false) {
                return 'schema_invalid';
            }
        }

        foreach (['understanding', 'reply_draft', 'grounded_response'] as $stage) {
            $runtime = is_array($bundle[$stage] ?? null) ? $bundle[$stage] : [];

            if (($runtime['degraded_mode'] ?? false) === true) {
                return 'degraded';
            }
        }

        foreach (['understanding', 'reply_draft', 'grounded_response'] as $stage) {
            $runtime = is_array($bundle[$stage] ?? null) ? $bundle[$stage] : [];

            if (($runtime['used_fallback_model'] ?? false) === true) {
                return 'fallback_model';
            }
        }

        foreach (['understanding', 'reply_draft', 'grounded_response'] as $stage) {
            $runtime = is_array($bundle[$stage] ?? null) ? $bundle[$stage] : [];

            if (($runtime['status'] ?? null) === 'success') {
                return 'healthy';
            }
        }

        return 'unknown';
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
