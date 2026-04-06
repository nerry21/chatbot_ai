<?php

namespace App\Services\Chatbot;

use App\Services\AI\GroundedResponseComposerService;
use App\Models\BookingRequest;
use App\Models\Conversation;
use App\Models\Customer;
use App\Services\AI\IntentClassifierService;
use App\Services\AI\ResponseGeneratorService;
use App\Services\AI\ResponseValidationService;
use App\Services\AI\RuleEngineService;
use App\Services\Booking\BookingConfirmationService;
use App\Services\Booking\RouteValidationService;
use App\Services\Chatbot\Guardrails\HallucinationGuardService;
use App\Services\Chatbot\Guardrails\PolicyGuardService;

class ReplyOrchestratorService
{
    /**
     * Human-readable label for each required booking field.
     * Used when generating the "missing fields" prompt.
     *
     * @var array<string, string>
     */
    private const FIELD_LABELS = [
        'pickup_location' => 'titik penjemputan',
        'destination'     => 'tujuan perjalanan',
        'passenger_name'  => 'nama penumpang',
        'passenger_count' => 'jumlah penumpang',
        'departure_date'  => 'tanggal keberangkatan',
        'departure_time'  => 'jam keberangkatan',
        'payment_method'  => 'metode pembayaran',
    ];

    public function __construct(
        private readonly IntentClassifierService $intentClassificationService,
        private readonly ResponseGeneratorService $replyGenerationService,
        private readonly RuleEngineService $ruleEngineService,
        private readonly ResponseValidationService $responseValidationService,
        private readonly PolicyGuardService $policyGuardService,
        private readonly HallucinationGuardService $hallucinationGuardService,
        private readonly ConversationReplyGuardService $conversationReplyGuardService,
        private readonly GroundedResponseComposerService $groundedResponseComposerService,
        private readonly BookingConfirmationService $confirmationService,
        private readonly RouteValidationService $routeValidator,
    ) {}

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function orchestrate(array $context): array
    {
        $intentResult = is_array($context['intent_result'] ?? null)
            ? $context['intent_result']
            : $this->intentClassificationService->classify($context);

        $replyDraft = is_array($context['reply_result'] ?? null)
            ? $context['reply_result']
            : $this->replyGenerationService->generate(
                context: $context,
                intentResult: $intentResult,
            );

        $ruleEvaluation = $this->ruleEngineService->evaluateOperationalRules(
            context: $context,
            intentResult: $intentResult,
            replyResult: $replyDraft,
        );

        $hasForcingAction =
            (($ruleEvaluation['actions']['force_handoff'] ?? false) === true)
            || (($ruleEvaluation['actions']['force_safe_fallback'] ?? false) === true)
            || (($ruleEvaluation['actions']['force_ask_missing_data'] ?? false) === true);

        if ($hasForcingAction) {
            $replyDraft = $this->ruleEngineService->buildSafeFallbackFromRules(
                context: $context,
                ruleEvaluation: $ruleEvaluation,
            );
        }

        $finalReply = $this->responseValidationService->validateAndFinalize(
            replyResult: $replyDraft,
            context: $context,
            intentResult: $intentResult,
            ruleEvaluation: $ruleEvaluation,
        );

        return [
            'intent_result' => $intentResult,
            'rule_evaluation' => $ruleEvaluation,
            'reply_result' => $finalReply,
        ];
    }

    /**
     * @param  array<string, mixed>  $orchestrated
     * @return array<string, mixed>
     */
    public function buildAuditSnapshot(array $orchestrated): array
    {
        $intent = is_array($orchestrated['intent_result'] ?? null) ? $orchestrated['intent_result'] : [];
        $rules = is_array($orchestrated['rule_evaluation'] ?? null) ? $orchestrated['rule_evaluation'] : [];
        $reply = is_array($orchestrated['reply_result'] ?? null) ? $orchestrated['reply_result'] : [];

        return [
            'intent' => $intent['intent'] ?? null,
            'intent_confidence' => $intent['confidence'] ?? null,
            'should_escalate' => $reply['should_escalate'] ?? false,
            'handoff_reason' => $reply['handoff_reason'] ?? null,
            'next_action' => $reply['next_action'] ?? null,
            'rule_hits' => $rules['rule_hits'] ?? [],
            'reply_source' => $reply['meta']['decision_source'] ?? $reply['meta']['source'] ?? null,
        ];
    }

    /**
     * Snapshot final untuk audit, observability, dan CRM writeback.
     *
     * @param  array<string, mixed>  $intentResult
     * @param  array<string, mixed>  $entityResult
     * @param  array<string, mixed>  $replyResult
     * @param  array<string, mixed>|null  $bookingDecision
     * @return array<string, mixed>
     */
    public function buildFinalSnapshot(
        array $intentResult,
        array $entityResult,
        array $replyResult,
        ?array $bookingDecision = null,
    ): array {
        $replyMeta = is_array($replyResult['meta'] ?? null) ? $replyResult['meta'] : [];
        $decisionTrace = $this->normalizeDecisionTrace(
            is_array($replyMeta['decision_trace'] ?? null) ? $replyMeta['decision_trace'] : [],
            $intentResult,
            $replyResult,
        );

        $finalGuardTrace = is_array($replyMeta['decision_trace_final_guard'] ?? null)
            ? $replyMeta['decision_trace_final_guard']
            : [];

        return [
            'trace_id' => $decisionTrace['trace_id'] ?? null,
            'intent' => $intentResult['intent'] ?? null,
            'intent_confidence' => $intentResult['confidence'] ?? null,
            'intent_reasoning' => $intentResult['reasoning_short'] ?? null,
            'entity_keys' => array_values(array_map('strval', array_keys($entityResult))),
            'reply_source' => $replyMeta['decision_source'] ?? $replyMeta['source'] ?? null,
            'reply_action' => $replyMeta['action'] ?? ($replyResult['next_action'] ?? null),
            'reply_force_handoff' => (bool) ($replyMeta['force_handoff'] ?? ($replyResult['should_escalate'] ?? false)),
            'reply_next_action' => $replyResult['next_action'] ?? null,
            'handoff_reason' => $replyResult['handoff_reason'] ?? null,
            'booking_action' => $bookingDecision['action'] ?? null,
            'booking_status' => $bookingDecision['booking_status'] ?? null,
            'is_fallback' => (bool) ($replyResult['is_fallback'] ?? false),
            'grounding_source' => $replyMeta['grounding_source'] ?? null,
            'used_crm_facts' => is_array($replyResult['used_crm_facts'] ?? null)
                ? array_values(array_unique($replyResult['used_crm_facts']))
                : [],
            'final_guard_action' => $replyMeta['final_guard_action'] ?? $replyMeta['action'] ?? null,
            'final_guard_verdict' => $replyMeta['verdict'] ?? null,
            'policy_verdict' => $replyMeta['policy_verdict'] ?? null,
            'grounding_verdict' => $replyMeta['grounding_verdict'] ?? null,
            'decided_by' => $replyMeta['decided_by'] ?? 'conversation_reply_guard',
            'orchestrator_role' => 'pipeline_aggregator',
            'decision_trace' => $decisionTrace,
            'decision_trace_final_guard' => $finalGuardTrace !== [] ? $finalGuardTrace : null,
        ];
    }

    /**
     * Hardening akhir reply supaya grounded, patuh policy, bebas halusinasi,
     * dan konsisten dengan state conversation + orchestration snapshot.
     *
     * @param  array<string, mixed>  $replyDraft
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $intentResult
     * @param  array<string, mixed>  $orchestrationSnapshot
     * @param  array<int, mixed>  $knowledgeHits
     * @param  array<string, mixed>|null  $faqResult
     * @return array<string, mixed>
     */
    public function hardenFinalReply(
        array $replyDraft,
        array $context,
        array $intentResult = [],
        array $orchestrationSnapshot = [],
        array $knowledgeHits = [],
        ?array $faqResult = null,
    ): array {
        $understandingRuntime = is_array($context['understanding_runtime'] ?? null)
            ? $context['understanding_runtime']
            : [];

        $draftRuntime = is_array($replyDraft['meta']['llm_runtime'] ?? null)
            ? $replyDraft['meta']['llm_runtime']
            : [];

        $grounded = $this->groundedResponseComposerService->composeGroundedReply(
            replyDraft: $replyDraft,
            context: $context,
            intentResult: $intentResult,
            orchestrationSnapshot: $orchestrationSnapshot,
            knowledgeHits: $knowledgeHits,
            faqResult: $faqResult,
        );

        $groundedRuntime = is_array($grounded['meta']['llm_runtime'] ?? null)
            ? $grounded['meta']['llm_runtime']
            : [];

        $afterHallucination = $this->hallucinationGuardService->guardReply(
            conversation: $context['conversation'],
            intentResult: $intentResult,
            reply: $grounded,
            context: [
                ...$context,
                'faq_result' => $faqResult ?? [],
                'knowledge_hits' => $knowledgeHits,
                'llm_runtime' => [
                    'understanding' => $understandingRuntime,
                    'reply_draft' => $draftRuntime,
                    'grounded_response' => $groundedRuntime,
                ],
            ],
        );

        $policyReport = $this->policyGuardService->evaluatePolicyCompliance(
            replyResult: $afterHallucination,
            context: $context,
            intentResult: $intentResult,
            orchestrationSnapshot: $orchestrationSnapshot,
        );

        $afterPolicy = $this->policyGuardService->applyPolicyFallback(
            replyResult: $afterHallucination,
            policyReport: $policyReport,
            context: $context,
        );

        $enrichedContext = $this->enrichContextForFinalGuard(
            context: $context,
            policyReport: $policyReport,
            afterHallucination: $afterHallucination,
            orchestrationSnapshot: $orchestrationSnapshot,
            intentResult: $intentResult,
        );

        $final = $this->conversationReplyGuardService->guardConversationReply(
            replyResult: $afterPolicy,
            context: $enrichedContext,
            intentResult: $intentResult,
            orchestrationSnapshot: $orchestrationSnapshot,
        );

        $final['reply'] = (string) ($final['reply'] ?? $final['text'] ?? '');
        $final['text'] = $final['reply'];
        $final['message_type'] = $final['message_type'] ?? 'text';
        $final['outbound_payload'] = is_array($final['outbound_payload'] ?? null) ? $final['outbound_payload'] : [];
        $final['used_crm_facts'] = array_values(array_unique(array_filter(
            is_array($final['used_crm_facts'] ?? null) ? $final['used_crm_facts'] : [],
        )));
        $final['safety_notes'] = array_values(array_unique(array_filter(
            is_array($final['safety_notes'] ?? null) ? $final['safety_notes'] : [],
        )));
        $final['grounding_notes'] = array_values(array_unique(array_filter(
            is_array($final['grounding_notes'] ?? null) ? $final['grounding_notes'] : [],
        )));

        $existingMeta = is_array($final['meta'] ?? null) ? $final['meta'] : [];
        $finalGuardAction = $existingMeta['action'] ?? $final['next_action'] ?? null;
        $finalGuardTrace = is_array($existingMeta['decision_trace_final_guard'] ?? null)
            ? $existingMeta['decision_trace_final_guard']
            : [];

        $decisionTrace = $this->mergeDecisionTraceParts(
            $existingMeta['decision_trace'] ?? [],
            $afterHallucination['meta']['decision_trace'] ?? [],
            $policyReport['decision_trace_policy'] ?? [],
            [
                'understanding' => [
                    'runtime' => $understandingRuntime,
                    'runtime_health' => $intentResult['runtime_health'] ?? null,
                    'model_used' => $intentResult['model_used'] ?? null,
                    'provider' => $intentResult['provider'] ?? null,
                    'runtime_status' => $intentResult['runtime_status'] ?? null,
                    'degraded_mode' => $intentResult['degraded_mode'] ?? null,
                    'schema_valid' => $intentResult['schema_valid'] ?? null,
                    'used_fallback_model' => $intentResult['used_fallback_model'] ?? null,
                ],
                'llm_runtime' => [
                    'reply_draft' => $draftRuntime,
                    'grounded_response' => $groundedRuntime,
                ],
                'outcome' => [
                    'final_action' => $finalGuardAction,
                    'decided_by' => 'conversation_reply_guard',
                    'reply_action' => $final['next_action'] ?? null,
                    'handoff' => (bool) ($final['should_escalate'] ?? false),
                    'handoff_reason' => $final['handoff_reason'] ?? null,
                    'is_fallback' => (bool) ($final['is_fallback'] ?? false),
                ],
                'policy' => [
                    'violations' => $policyReport['violations'] ?? [],
                    'verdict' => $policyReport['verdict'] ?? $policyReport['policy_verdict'] ?? null,
                ],
                'grounding' => [
                    'source' => $existingMeta['grounding_source'] ?? null,
                    'used_crm_facts' => is_array($final['used_crm_facts'] ?? null)
                        ? array_values(array_unique($final['used_crm_facts']))
                        : [],
                    'runtime_health' => $grounded['meta']['runtime_health'] ?? null,
                    'llm_runtime' => $groundedRuntime,
                ],
            ],
        );

        $final['meta'] = array_merge(
            $existingMeta,
            [
                'trace_id' => $decisionTrace['trace_id'] ?? null,
                'hardening_applied' => true,
                'orchestrator_role' => 'pipeline_aggregator',
                'is_final_decider' => false,
                'decided_by' => 'conversation_reply_guard',
                'final_guard_action' => $finalGuardAction,
                'decision_source' => $existingMeta['decision_source'] ?? $existingMeta['source'] ?? 'reply_hardening',
                'policy_violations' => $policyReport['violations'] ?? [],
                'policy_verdict' => $policyReport['verdict'] ?? $policyReport['policy_verdict'] ?? null,
                'llm_runtime' => [
                    'understanding' => $understandingRuntime,
                    'reply_draft' => $draftRuntime,
                    'grounded_response' => $groundedRuntime,
                ],
                'decision_trace' => $decisionTrace,
                'decision_trace_final_guard' => $finalGuardTrace,
            ],
        );

        $final['is_fallback'] = (bool) ($final['is_fallback'] ?? str_contains((string) ($final['meta']['source'] ?? ''), 'fallback'));

        return $final;
    }

    /**
     * @param  array<string, mixed>  $replyDraft
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $intentResult
     * @param  array<string, mixed>  $snapshot
     * @param  array<int, mixed>  $knowledgeHits
     * @param  array<string, mixed>|null  $faqResult
     * @return array<string, mixed>
     */
    public function finalizeReplyWithHardening(
        array $replyDraft,
        array $context,
        array $intentResult,
        array $snapshot,
        array $knowledgeHits = [],
        ?array $faqResult = null,
    ): array {
        $final = $this->hardenFinalReply(
            replyDraft: $replyDraft,
            context: $context,
            intentResult: $intentResult,
            orchestrationSnapshot: $snapshot,
            knowledgeHits: $knowledgeHits,
            faqResult: $faqResult,
        );

        $finalMeta = is_array($final['meta'] ?? null) ? $final['meta'] : [];
        $trace = $this->normalizeDecisionTrace(
            is_array($finalMeta['decision_trace'] ?? null) ? $finalMeta['decision_trace'] : [],
            $intentResult,
            $final,
        );

        $final['meta'] = array_merge($finalMeta, [
            'trace_id' => $trace['trace_id'] ?? null,
            'decision_trace' => $trace,
        ]);

        return $final;
    }

    /**
     * Memperkaya context dengan verdict dari pipeline (policy + hallucination)
     * sebelum diserahkan ke final guard, agar collectFinalGuardSignals() bisa
     * membaca semua sinyal secara akurat.
     *
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $policyReport
     * @param  array<string, mixed>  $afterHallucination
     * @param  array<string, mixed>  $orchestrationSnapshot
     * @param  array<string, mixed>  $intentResult
     * @return array<string, mixed>
     */
    private function enrichContextForFinalGuard(
        array $context,
        array $policyReport,
        array $afterHallucination,
        array $orchestrationSnapshot,
        array $intentResult,
    ): array {
        $hallucinationMeta = is_array($afterHallucination['meta'] ?? null)
            ? $afterHallucination['meta']
            : [];

        $hallucinationGuardVerdict = is_array($hallucinationMeta['hallucination_guard'] ?? null)
            ? $hallucinationMeta['hallucination_guard']
            : [
                'verdict' => $hallucinationMeta['verdict'] ?? null,
                'reason' => $hallucinationMeta['hallucination_reason'] ?? null,
                'force_handoff' => (bool) ($hallucinationMeta['force_handoff'] ?? false),
                'force_clarification' => (bool) ($hallucinationMeta['force_clarification'] ?? false),
            ];

        return array_merge($context, [
            'policy_guard' => array_merge($policyReport, [
                'verdict' => $policyReport['verdict'] ?? $policyReport['policy_verdict'] ?? 'allow',
                'force_handoff' => (bool) ($policyReport['force_handoff'] ?? false),
                'force_clarification' => (bool) ($policyReport['force_clarification'] ?? false),
                'reason_code' => $policyReport['reason_code'] ?? null,
                'reasons' => $policyReport['reasons'] ?? [],
            ]),
            'hallucination_guard' => $hallucinationGuardVerdict,
            'reply_orchestration' => [
                'reply_force_handoff' => (bool) ($orchestrationSnapshot['reply_force_handoff'] ?? false),
                'needs_human' => (bool) ($orchestrationSnapshot['needs_human'] ?? false),
                'reply_action' => $orchestrationSnapshot['reply_action'] ?? null,
                'reply_guard_action' => $orchestrationSnapshot['reply_guard_action'] ?? null,
                'clarification_question' => $intentResult['clarification_question'] ?? null,
            ],
        ]);
    }

    /**
     * Snapshot posisi orchestrator: agregator, bukan pengambil keputusan.
     * Dapat dipakai untuk audit, CRM writeback, atau observability.
     *
     * @param  array<string, mixed>  $intentResult
     * @param  array<string, mixed>  $policyReport
     * @param  array<string, mixed>  $groundedMeta
     * @param  array<string, mixed>  $hallucinationMeta
     * @param  array<string, mixed>  $finalGuardMeta
     * @return array<string, mixed>
     */
    public function buildOrchestratorSnapshot(
        array $intentResult,
        array $policyReport,
        array $groundedMeta,
        array $hallucinationMeta,
        array $finalGuardMeta,
    ): array {
        return [
            'orchestrator_role' => 'pipeline_aggregator',
            'is_final_decider' => false,
            'decided_by' => 'conversation_reply_guard',
            'intent' => $intentResult['intent'] ?? null,
            'intent_confidence' => $intentResult['confidence'] ?? null,
            'policy_verdict' => $policyReport['verdict'] ?? $policyReport['policy_verdict'] ?? null,
            'grounding_verdict' => $groundedMeta['grounding_verdict'] ?? null,
            'grounding_source' => $groundedMeta['grounding_source'] ?? null,
            'hallucination_verdict' => $hallucinationMeta['verdict'] ?? null,
            'runtime_health' => $hallucinationMeta['runtime_health'] ?? null,
            'final_guard_action' => $finalGuardMeta['action'] ?? null,
            'final_guard_verdict' => $finalGuardMeta['verdict'] ?? null,
            'final_guard_is_final_decider' => (bool) ($finalGuardMeta['is_final_decider'] ?? true),
        ];
    }

    /**
     * @param  mixed  ...$parts
     * @return array<string, mixed>
     */
    private function mergeDecisionTraceParts(...$parts): array
    {
        $merged = [];

        foreach ($parts as $part) {
            if (! is_array($part) || $part === []) {
                continue;
            }

            $normalized = $this->normalizeDecisionTrace($part);

            foreach ($normalized as $key => $value) {
                if (! array_key_exists($key, $merged)) {
                    $merged[$key] = $value;
                    continue;
                }

                if (is_array($merged[$key]) && is_array($value)) {
                    $merged[$key] = array_replace_recursive($merged[$key], $value);
                    continue;
                }

                if ($value !== null && $value !== '') {
                    $merged[$key] = $value;
                }
            }
        }

        if (! isset($merged['trace_id']) || ! is_scalar($merged['trace_id']) || trim((string) $merged['trace_id']) === '') {
            $merged['trace_id'] = 'trace-'.now()->format('YmdHis').'-'.substr(md5((string) microtime(true)), 0, 8);
        }

        return $merged;
    }

    /**
     * @param  array<string, mixed>  $trace
     * @param  array<string, mixed>  $intentResult
     * @param  array<string, mixed>  $replyResult
     * @return array<string, mixed>
     */
    private function normalizeDecisionTrace(
        array $trace,
        array $intentResult = [],
        array $replyResult = [],
    ): array {
        if (array_is_list($trace)) {
            $merged = [];

            foreach ($trace as $part) {
                if (is_array($part)) {
                    $merged = array_replace_recursive($merged, $part);
                }
            }

            $trace = $merged;
        }

        $outcome = is_array($trace['outcome'] ?? null) ? $trace['outcome'] : [];
        $policy = is_array($trace['policy'] ?? null) ? $trace['policy'] : [];
        $grounding = is_array($trace['grounding'] ?? null) ? $trace['grounding'] : [];
        $understanding = is_array($trace['understanding'] ?? null) ? $trace['understanding'] : [];

        $traceId = $trace['trace_id'] ?? $replyResult['trace_id'] ?? $replyResult['meta']['trace_id'] ?? null;

        if (! is_scalar($traceId) || trim((string) $traceId) === '') {
            $traceId = 'trace-'.now()->format('YmdHis').'-'.substr(md5((string) microtime(true)), 0, 8);
        }

        return [
            'trace_id' => trim((string) $traceId),
            'understanding' => array_filter([
                'intent' => $intentResult['intent'] ?? $understanding['intent'] ?? null,
                'confidence' => $intentResult['confidence'] ?? $understanding['confidence'] ?? null,
                'reasoning_short' => $intentResult['reasoning_short'] ?? $understanding['reasoning_short'] ?? null,
                'runtime_health' => $intentResult['runtime_health'] ?? $understanding['runtime_health'] ?? null,
                'model_used' => $intentResult['model_used'] ?? $understanding['model_used'] ?? null,
                'provider' => $intentResult['provider'] ?? $understanding['provider'] ?? null,
                'runtime_status' => $intentResult['runtime_status'] ?? $understanding['runtime_status'] ?? null,
                'degraded_mode' => $intentResult['degraded_mode'] ?? $understanding['degraded_mode'] ?? null,
                'schema_valid' => $intentResult['schema_valid'] ?? $understanding['schema_valid'] ?? null,
                'used_fallback_model' => $intentResult['used_fallback_model'] ?? $understanding['used_fallback_model'] ?? null,
                'runtime' => $understanding['runtime'] ?? null,
            ], fn ($v) => $v !== null && $v !== ''),
            'outcome' => array_filter([
                'final_action' => $outcome['final_action'] ?? $outcome['final_decision'] ?? null,
                'decided_by' => $outcome['decided_by'] ?? 'conversation_reply_guard',
                'reply_action' => $outcome['reply_action'] ?? ($replyResult['next_action'] ?? null),
                'handoff' => $outcome['handoff'] ?? ($replyResult['should_escalate'] ?? null),
                'handoff_reason' => $outcome['handoff_reason'] ?? ($replyResult['handoff_reason'] ?? null),
                'is_fallback' => $outcome['is_fallback'] ?? ($replyResult['is_fallback'] ?? null),
            ], fn ($v) => $v !== null && $v !== ''),
            'policy' => array_filter($policy, fn ($v) => $v !== null && $v !== ''),
            'grounding' => array_filter($grounding, fn ($v) => $v !== null && $v !== ''),
        ];
    }

    /**
     * Compose the final outbound reply text by combining:
     *  - booking engine decision (takes priority when present)
     *  - AI-generated reply from Tahap 3 (fallback when no booking decision)
     *
     * Required context keys:
     *   conversation  (Conversation)
     *   customer      (Customer)
     *   intentResult  (array)
     *   entityResult  (array)
     *   replyResult   (array{text: string, is_fallback: bool})
     *
     * Optional context keys:
     *   bookingDecision  (array|null)   — output of BookingAssistantService::decideNextStep()
     *   booking          (BookingRequest|null)
     *
     * @param  array<string, mixed>  $context
     * @return array{text: string, is_fallback: bool, meta: array<string, mixed>}
     */
    public function compose(array $context): array
    {
        /** @var Conversation $conversation */
        $conversation = $context['conversation'];
        /** @var Customer $customer */
        $customer        = $context['customer'];
        $intentResult    = $context['intentResult'] ?? [];
        $entityResult    = $context['entityResult'] ?? [];
        $replyResult     = $context['replyResult']  ?? ['text' => '', 'is_fallback' => true];
        $bookingDecision = $context['bookingDecision'] ?? null;
        /** @var BookingRequest|null $booking */
        $booking = $context['booking'] ?? null;

        $customerName = $customer->name ?? null;

        // ── No booking engine involvement → pass through AI reply ─────────
        if ($bookingDecision === null) {
            return [
                'text'        => $replyResult['text'],
                'is_fallback' => $replyResult['is_fallback'],
                'meta'        => ['source' => 'ai_reply'],
            ];
        }

        $action = $bookingDecision['action'] ?? 'general_reply';

        $text = match($action) {
            'ask_missing_fields' => $this->composeMissingFields(
                missingFields : $bookingDecision['missing_fields'] ?? [],
                customerName  : $customerName,
            ),

            'unsupported_route'  => $this->composeUnsupportedRoute(
                booking      : $booking,
                customerName : $customerName,
            ),

            'ask_confirmation'   => $this->composeAskConfirmation(
                booking      : $booking,
                customerName : $customerName,
            ),

            'confirmed'          => $this->composeConfirmed($customerName),

            'booking_cancelled'  => $this->composeCancelled($customerName),

            'unavailable'        => $this->composeUnavailable(
                reason       : $bookingDecision['reason'] ?? null,
                customerName : $customerName,
            ),

            'general_reply'      => $replyResult['text'],

            default              => $replyResult['text'],
        };

        return [
            'text'        => $text !== '' ? $text : $replyResult['text'],
            'is_fallback' => false,
            'meta'        => [
                'source'         => 'booking_engine',
                'action'         => $action,
                'booking_status' => $bookingDecision['booking_status'] ?? null,
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // Reply composers
    // -------------------------------------------------------------------------

    /** @param array<int, string> $missingFields */
    private function composeMissingFields(array $missingFields, ?string $customerName): string
    {
        $greeting = $customerName ? "Halo, {$customerName}! " : 'Halo! ';

        $fieldLabels = array_map(
            fn (string $field) => '- ' . (self::FIELD_LABELS[$field] ?? $field),
            $missingFields,
        );

        $listStr = implode("\n", $fieldLabels);

        return <<<TEXT
        {$greeting}Untuk melengkapi pesanan Anda, kami masih membutuhkan informasi berikut:

        {$listStr}

        Mohon informasikan data di atas agar kami bisa memproses pesanan Anda.
        TEXT;
    }

    private function composeUnsupportedRoute(?BookingRequest $booking, ?string $customerName): string
    {
        $greeting = $customerName ? "Mohon maaf, {$customerName}." : 'Mohon maaf.';

        $pickup = $booking?->pickup_location ?? 'yang Anda pilih';
        $dest   = $booking?->destination     ?? 'tujuan tersebut';

        $supported = $this->routeValidator->supportedPickups();
        $routeHint = ! empty($supported)
            ? "\n\nKota keberangkatan yang saat ini kami layani: " . implode(', ', $supported) . '.'
            : '';

        return <<<TEXT
        {$greeting} Rute dari *{$pickup}* menuju *{$dest}* belum tersedia dalam layanan kami saat ini.{$routeHint}

        Ada rute lain yang bisa kami bantu?
        TEXT;
    }

    private function composeAskConfirmation(?BookingRequest $booking, ?string $customerName): string
    {
        if ($booking === null) {
            return 'Mohon konfirmasikan pesanan Anda dengan membalas YA atau BENAR.';
        }

        return $this->confirmationService->buildSummary($booking);
    }

    private function composeConfirmed(?string $customerName): string
    {
        $name = $customerName ? ", {$customerName}" : '';

        return <<<TEXT
        Terima kasih{$name}! Permintaan pemesanan Anda telah berhasil kami catat.

        Tim kami akan segera menghubungi Anda untuk konfirmasi jadwal dan detail pembayaran. Mohon pastikan nomor WhatsApp Anda aktif.
        TEXT;
    }

    private function composeCancelled(?string $customerName): string
    {
        $name = $customerName ? ", {$customerName}" : '';

        return "Baik{$name}, pesanan Anda telah kami batalkan. Jika suatu saat Anda ingin memesan kembali, kami siap membantu.";
    }

    private function composeUnavailable(?string $reason, ?string $customerName): string
    {
        $greeting = $customerName ? "Mohon maaf, {$customerName}." : 'Mohon maaf.';
        $detail   = $reason ? " {$reason}" : ' Slot keberangkatan yang Anda pilih sedang tidak tersedia.';

        return "{$greeting}{$detail} Apakah Anda ingin mencoba tanggal atau waktu lain?";
    }
}
