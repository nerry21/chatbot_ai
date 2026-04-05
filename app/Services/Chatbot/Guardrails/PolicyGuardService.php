<?php

namespace App\Services\Chatbot\Guardrails;

use App\Enums\IntentType;
use App\Models\Conversation;

class PolicyGuardService
{
    public function __construct(
        private readonly AdminTakeoverGuardService $adminTakeoverGuard,
    ) {
    }

    /**
     * @param  array<string, mixed>  $intentResult
     * @param  array<string, mixed>  $entityResult
     * @param  array<string, mixed>  $understandingResult
     * @param  array<string, mixed>  $resolvedContext
     * @param  array<string, mixed>  $conversationState
     * @return array{
     *     intent_result: array<string, mixed>,
     *     entity_result: array<string, mixed>,
     *     meta: array{
     *         guard_group: string,
     *         action: string,
     *         block_auto_reply: bool,
     *         reasons: array<int, string>,
     *         hydrated_context_fields: array<int, string>,
     *         crm_policy_source?: string,
     *         crm_policy_snapshot?: array<string, mixed>
     *     }
     * }
     */
    public function guard(
        Conversation $conversation,
        array $intentResult,
        array $entityResult,
        array $understandingResult = [],
        array $resolvedContext = [],
        array $conversationState = [],
        array $crmContext = [],
    ): array {
        $intentResult = $this->normalizeIntentResult($intentResult);
        [$entityResult, $hydratedContextFields] = $this->hydrateEntitiesFromResolvedContext(
            entityResult: $entityResult,
            intentResult: $intentResult,
            resolvedContext: $resolvedContext,
        );

        $reasons = [];
        $action = 'allow';

        $traceId = $this->resolveTraceId(
            $understandingResult,
            $resolvedContext,
            $crmContext,
        );

        $crmBusinessFlags = is_array($crmContext['business_flags'] ?? null)
            ? $crmContext['business_flags']
            : [];

        $crmEscalation = is_array($crmContext['escalation'] ?? null)
            ? $crmContext['escalation']
            : [];

        $crmConversation = is_array($crmContext['conversation'] ?? null)
            ? $crmContext['conversation']
            : [];

        $crmLeadPipeline = is_array($crmContext['lead_pipeline'] ?? null)
            ? $crmContext['lead_pipeline']
            : [];

        if (($crmBusinessFlags['bot_paused'] ?? false) === true) {
            $reasons[] = 'crm_bot_paused';

            return [
                'intent_result' => $this->handoffIntent(
                    intentResult: $intentResult,
                    reasoning: 'Bot sedang dipause dari konteks CRM/runtime.',
                ),
                'entity_result' => $entityResult,
                'meta' => [
                    'guard_group' => 'policy',
                    'action' => 'blocked_bot_paused',
                    'block_auto_reply' => true,
                    'reasons' => $reasons,
                    'hydrated_context_fields' => $hydratedContextFields,
                    'crm_policy_source' => 'business_flags.bot_paused',
                    'crm_policy_snapshot' => $this->crmPolicySnapshot(
                        crmBusinessFlags: $crmBusinessFlags,
                        crmEscalation: $crmEscalation,
                        crmLeadPipeline: $crmLeadPipeline,
                    ),
                    'decision_trace_policy' => $this->decisionTracePolicy(
                        traceId: $traceId,
                        action: 'blocked_bot_paused',
                        reasons: $reasons,
                        crmBusinessFlags: $crmBusinessFlags,
                        crmEscalation: $crmEscalation,
                        crmLeadPipeline: $crmLeadPipeline,
                        crmSource: 'business_flags.bot_paused',
                    ),
                ],
            ];
        }

        if (
            ($crmBusinessFlags['admin_takeover_active'] ?? false) === true
            || ($crmConversation['admin_takeover'] ?? false) === true
        ) {
            $reasons[] = 'crm_admin_takeover_active';

            return [
                'intent_result' => $this->handoffIntent(
                    intentResult: $intentResult,
                    reasoning: 'Admin takeover aktif, balasan AI dihentikan.',
                ),
                'entity_result' => $entityResult,
                'meta' => [
                    'guard_group' => 'policy',
                    'action' => 'blocked_admin_takeover',
                    'block_auto_reply' => true,
                    'reasons' => $reasons,
                    'hydrated_context_fields' => $hydratedContextFields,
                    'crm_policy_source' => 'business_flags.admin_takeover_active',
                    'crm_policy_snapshot' => $this->crmPolicySnapshot(
                        crmBusinessFlags: $crmBusinessFlags,
                        crmEscalation: $crmEscalation,
                        crmLeadPipeline: $crmLeadPipeline,
                    ),
                    'decision_trace_policy' => $this->decisionTracePolicy(
                        traceId: $traceId,
                        action: 'blocked_admin_takeover',
                        reasons: $reasons,
                        crmBusinessFlags: $crmBusinessFlags,
                        crmEscalation: $crmEscalation,
                        crmLeadPipeline: $crmLeadPipeline,
                        crmSource: 'business_flags.admin_takeover_active',
                    ),
                ],
            ];
        }

        if (($crmEscalation['has_open_escalation'] ?? false) === true) {
            $action = 'handoff';
            $reasons[] = 'crm_open_escalation';
            $intentResult = $this->handoffIntent(
                intentResult: $intentResult,
                reasoning: 'CRM menunjukkan ada eskalasi aktif, sehingga auto reply dibatasi.',
            );
        }

        if (
            ($crmBusinessFlags['needs_human_followup'] ?? false) === true
            || ($crmConversation['needs_human'] ?? false) === true
        ) {
            $action = 'handoff';
            $reasons[] = 'crm_needs_human_followup';
            $intentResult = $this->handoffIntent(
                intentResult: $intentResult,
                reasoning: 'Konteks CRM menandai percakapan ini perlu follow up manusia.',
            );
        }

        if (
            $this->adminTakeoverGuard->shouldSuppressAutomation($conversation)
            || (($conversationState['admin_takeover'] ?? false) === true)
            || (($resolvedContext['admin_takeover'] ?? false) === true)
        ) {
            $reasons[] = 'admin_takeover_active';

            return [
                'intent_result' => $this->handoffIntent(
                    intentResult: $intentResult,
                    reasoning: 'Admin takeover aktif; bot tidak boleh membalas otomatis.',
                ),
                'entity_result' => $entityResult,
                'meta' => [
                    'guard_group' => 'policy',
                    'action' => 'blocked_takeover',
                    'block_auto_reply' => true,
                    'reasons' => $reasons,
                    'hydrated_context_fields' => $hydratedContextFields,
                    'crm_policy_source' => 'runtime.admin_takeover',
                    'crm_policy_snapshot' => $this->crmPolicySnapshot(
                        crmBusinessFlags: $crmBusinessFlags,
                        crmEscalation: $crmEscalation,
                        crmLeadPipeline: $crmLeadPipeline,
                    ),
                    'decision_trace_policy' => $this->decisionTracePolicy(
                        traceId: $traceId,
                        action: 'blocked_takeover',
                        reasons: $reasons,
                        crmBusinessFlags: $crmBusinessFlags,
                        crmEscalation: $crmEscalation,
                        crmLeadPipeline: $crmLeadPipeline,
                        crmSource: 'runtime.admin_takeover',
                    ),
                ],
            ];
        }

        if ($this->isSensitiveIntent($intentResult)) {
            $action = 'handoff';
            $reasons[] = 'sensitive_intent';
            $intentResult = $this->handoffIntent(
                intentResult: $intentResult,
                reasoning: 'Intent sensitif terdeteksi dan wajib diarahkan ke admin.',
            );
        }

        if (in_array(($crmLeadPipeline['stage'] ?? null), ['complaint', 'refund', 'legal', 'high_risk'], true)) {
            $action = 'handoff';
            $reasons[] = 'crm_high_risk_pipeline_stage';
            $intentResult = $this->handoffIntent(
                intentResult: $intentResult,
                reasoning: 'Tahap pipeline CRM menunjukkan kasus berisiko tinggi.',
            );
        }

        if (($intentResult['handoff_recommended'] ?? false) === true) {
            $action = 'handoff';
            $reasons[] = 'llm_recommended_handoff';
            $intentResult = $this->handoffIntent(
                intentResult: $intentResult,
                reasoning: 'Understanding LLM merekomendasikan handoff ke admin.',
            );
        }

        $hasBookingContext = $this->hasBookingContext($entityResult, $resolvedContext, $conversationState);
        $intent = IntentType::tryFrom((string) ($intentResult['intent'] ?? ''));

        if ($this->shouldClarify($intentResult, $hasBookingContext)) {
            $action = $action === 'allow' ? 'clarify' : $action;
            $reasons[] = 'low_confidence_or_ambiguous_understanding';

            if (
                $hasBookingContext
                && in_array($intent, [
                    IntentType::Unknown,
                    IntentType::OutOfScope,
                    IntentType::Support,
                    IntentType::PertanyaanTidakTerjawab,
                    null,
                ], true)
            ) {
                $intentResult['intent'] = IntentType::Booking->value;
            }

            $intentResult['needs_clarification'] = true;
            $clarificationQuestion = $this->normalizeText($intentResult['clarification_question'] ?? null);
            if ($clarificationQuestion === null || $this->isGenericClarificationQuestion($clarificationQuestion)) {
                $clarificationQuestion = $this->clarificationQuestionFor($intentResult, $entityResult, $resolvedContext, $conversationState);
            }
            $intentResult['clarification_question'] = $clarificationQuestion;
            $intentResult['reasoning_short'] = 'Understanding masih ambigu; bot meminta klarifikasi yang aman.';
        }

        if ($this->requiresPriceRouteClarification($intentResult, $entityResult)) {
            $action = 'clarify';
            $reasons[] = 'price_requires_route_context';
            $intentResult['needs_clarification'] = true;
            $intentResult['clarification_question'] = 'Izin Bapak/Ibu, untuk cek ongkos kami perlu titik jemput dan tujuan perjalanannya ya.';
        }

        if (
            $action === 'allow'
            && in_array($intent, [IntentType::Support, IntentType::HumanHandoff], true)
        ) {
            $action = 'handoff';
            $reasons[] = 'support_or_handoff_intent';
            $intentResult = $this->handoffIntent(
                intentResult: $intentResult,
                reasoning: 'Pertanyaan diarahkan ke admin sesuai policy guard.',
            );
        }

        if (($understandingResult['uses_previous_context'] ?? false) === true) {
            $intentResult['uses_previous_context'] = true;
        }

        return [
            'intent_result' => $intentResult,
            'entity_result' => $entityResult,
            'meta' => [
                'guard_group' => 'policy',
                'action' => $action,
                'block_auto_reply' => $action !== 'allow',
                'reasons' => array_values(array_unique($reasons)),
                'hydrated_context_fields' => $hydratedContextFields,
                'crm_policy_snapshot' => $this->crmPolicySnapshot(
                    crmBusinessFlags: $crmBusinessFlags,
                    crmEscalation: $crmEscalation,
                    crmLeadPipeline: $crmLeadPipeline,
                ),
                'decision_trace_policy' => $this->decisionTracePolicy(
                    traceId: $traceId,
                    action: $action,
                    reasons: $reasons,
                    crmBusinessFlags: $crmBusinessFlags,
                    crmEscalation: $crmEscalation,
                    crmLeadPipeline: $crmLeadPipeline,
                    crmSource: $action !== 'allow' ? 'policy_guard' : null,
                ),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $replyResult
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $intentResult
     * @param  array<string, mixed>  $orchestrationSnapshot
     * @return array{is_compliant: bool, violations: array<int, string>}
     */
    public function evaluatePolicyCompliance(
        array $replyResult,
        array $context,
        array $intentResult = [],
        array $orchestrationSnapshot = [],
    ): array {
        $crm = is_array($context['crm_context'] ?? null) ? $context['crm_context'] : [];
        $conversation = is_array($crm['conversation'] ?? null) ? $crm['conversation'] : [];
        $flags = is_array($crm['business_flags'] ?? null) ? $crm['business_flags'] : [];
        $escalation = is_array($crm['escalation'] ?? null) ? $crm['escalation'] : [];
        $leadPipeline = is_array($crm['lead_pipeline'] ?? null) ? $crm['lead_pipeline'] : [];

        $reply = trim((string) ($replyResult['reply'] ?? $replyResult['text'] ?? ''));
        $intent = (string) ($intentResult['intent'] ?? 'unknown');
        $meta = is_array($replyResult['meta'] ?? null) ? $replyResult['meta'] : [];

        $violations = [];
        $decisionAction = 'allow';
        $blockAutoReply = false;

        if (($flags['admin_takeover_active'] ?? false) === true) {
            $decisionAction = 'handoff';
            $blockAutoReply = true;
            $violations[] = 'admin_takeover_active';
        }

        if (($flags['bot_paused'] ?? false) === true) {
            $decisionAction = 'handoff';
            $blockAutoReply = true;
            $violations[] = 'bot_paused_active';
        }

        if (($conversation['needs_human'] ?? false) === true || ($flags['needs_human_followup'] ?? false) === true) {
            $decisionAction = 'handoff';
            $violations[] = 'needs_human_followup';
        }

        if (($escalation['has_open_escalation'] ?? false) === true) {
            $decisionAction = 'handoff';
            $violations[] = 'open_escalation_active';
        }

        if ($this->isSensitiveIntent(['intent' => $intent])) {
            $decisionAction = 'handoff';
            $violations[] = 'sensitive_intent';
        }

        if (
            in_array(($leadPipeline['stage'] ?? null), ['complaint', 'refund', 'legal', 'high_risk'], true)
        ) {
            $decisionAction = 'handoff';
            $violations[] = 'crm_high_risk_pipeline_stage';
        }

        if (
            ($orchestrationSnapshot['reply_force_handoff'] ?? false) === true
            && (($replyResult['should_escalate'] ?? false) !== true)
        ) {
            $decisionAction = 'handoff';
            $violations[] = 'orchestration_handoff_not_respected';
        }

        if ($reply === '') {
            $violations[] = 'empty_reply_after_orchestration';
        }

        return [
            'is_compliant' => $violations === [],
            'violations' => array_values(array_unique($violations)),
            'decision_trace_policy' => [
                'trace_id' => $this->resolveTraceId(
                    $meta,
                    $context,
                    ['business_flags' => $flags, 'escalation' => $escalation, 'lead_pipeline' => $leadPipeline],
                ),
                'policy' => [
                    'stage' => 'policy_guard',
                    'action' => $decisionAction,
                    'blocked' => $blockAutoReply,
                    'force_handoff' => $decisionAction === 'handoff',
                    'reasons' => array_values(array_unique($violations)),
                    'reply_source' => $meta['decision_source'] ?? $meta['source'] ?? null,
                    'crm_policy_snapshot' => $this->crmPolicySnapshot(
                        crmBusinessFlags: $flags,
                        crmEscalation: $escalation,
                        crmLeadPipeline: $leadPipeline,
                    ),
                    'evaluated_at' => now()->toIso8601String(),
                ],
                'outcome' => [
                    'reply_action' => $replyResult['next_action'] ?? null,
                    'handoff' => $decisionAction === 'handoff',
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $replyResult
     * @param  array{is_compliant?: bool, violations?: array<int, string>}  $policyReport
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function applyPolicyFallback(
        array $replyResult,
        array $policyReport,
        array $context = [],
    ): array {
        if (($policyReport['is_compliant'] ?? true) === true) {
            return $replyResult;
        }

        return [
            'reply' => 'Baik, agar penanganannya tetap sesuai prosedur, percakapan ini akan saya teruskan ke admin kami ya.',
            'text' => 'Baik, agar penanganannya tetap sesuai prosedur, percakapan ini akan saya teruskan ke admin kami ya.',
            'tone' => 'empatik',
            'should_escalate' => true,
            'handoff_reason' => 'Policy guard fallback',
            'next_action' => 'handoff_admin',
            'data_requests' => [],
            'used_crm_facts' => [],
            'safety_notes' => array_values($policyReport['violations'] ?? []),
            'message_type' => 'text',
            'outbound_payload' => [],
            'is_fallback' => true,
            'meta' => [
                'force_handoff' => true,
                'source' => 'policy_guard_fallback',
                'decision_trace' => [
                    'trace_id' => $this->resolveTraceId($policyReport, $context),
                    'policy' => [
                        'stage' => 'policy_guard',
                        'action' => 'handoff',
                        'blocked' => true,
                        'force_handoff' => true,
                        'reasons' => array_values($policyReport['violations'] ?? []),
                        'evaluated_at' => now()->toIso8601String(),
                    ],
                    'outcome' => [
                        'final_decision' => 'policy_guard_fallback',
                        'reply_action' => 'handoff_admin',
                        'handoff' => true,
                        'handoff_reason' => 'Policy guard fallback',
                        'is_fallback' => true,
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $intentResult
     * @return array<string, mixed>
     */
    private function normalizeIntentResult(array $intentResult): array
    {
        return [
            'intent' => (string) ($intentResult['intent'] ?? IntentType::Unknown->value),
            'confidence' => min(1.0, max(0.0, (float) ($intentResult['confidence'] ?? 0.0))),
            'reasoning_short' => (string) ($intentResult['reasoning_short'] ?? 'Policy guard aktif.'),
            'sub_intent' => $intentResult['sub_intent'] ?? null,
            'needs_clarification' => (bool) ($intentResult['needs_clarification'] ?? false),
            'clarification_question' => $this->normalizeText($intentResult['clarification_question'] ?? null),
            'handoff_recommended' => (bool) ($intentResult['handoff_recommended'] ?? false),
            'uses_previous_context' => (bool) ($intentResult['uses_previous_context'] ?? false),
            'llm_primary' => (bool) ($intentResult['llm_primary'] ?? true),
        ];
    }

    /**
     * @param  array<string, mixed>  $entityResult
     * @param  array<string, mixed>  $intentResult
     * @param  array<string, mixed>  $resolvedContext
     * @return array{0: array<string, mixed>, 1: array<int, string>}
     */
    private function hydrateEntitiesFromResolvedContext(
        array $entityResult,
        array $intentResult,
        array $resolvedContext,
    ): array {
        $shouldHydrate = (($intentResult['uses_previous_context'] ?? false) === true)
            || (($resolvedContext['context_dependency_detected'] ?? false) === true);

        if (! $shouldHydrate) {
            return [$entityResult, []];
        }

        $mapping = [
            'pickup_location' => 'last_origin',
            'destination' => 'last_destination',
            'departure_date' => 'last_travel_date',
            'departure_time' => 'last_departure_time',
        ];

        $hydrated = [];

        foreach ($mapping as $entityKey => $contextKey) {
            if (! $this->isBlank($entityResult[$entityKey] ?? null)) {
                continue;
            }

            $candidate = $resolvedContext[$contextKey] ?? null;
            if ($this->isBlank($candidate)) {
                continue;
            }

            $entityResult[$entityKey] = $candidate;
            $hydrated[] = $entityKey;
        }

        return [$entityResult, $hydrated];
    }

    /**
     * @param  array<string, mixed>  $intentResult
     */
    private function shouldClarify(array $intentResult, bool $hasBookingContext): bool
    {
        if (($intentResult['needs_clarification'] ?? false) === true) {
            return true;
        }

        $confidence = (float) ($intentResult['confidence'] ?? 0.0);
        $threshold = (float) config(
            'chatbot.guards.policy_low_confidence_threshold',
            max((float) config('chatbot.ai_quality.low_confidence_threshold', 0.40), 0.55),
        );

        if ($confidence > min(1.0, max(0.0, $threshold))) {
            return false;
        }

        return $hasBookingContext || in_array((string) ($intentResult['intent'] ?? ''), [
            IntentType::Unknown->value,
            IntentType::OutOfScope->value,
            IntentType::PertanyaanTidakTerjawab->value,
        ], true);
    }

    /**
     * @param  array<string, mixed>  $entityResult
     * @param  array<string, mixed>  $resolvedContext
     * @param  array<string, mixed>  $conversationState
     */
    private function hasBookingContext(array $entityResult, array $resolvedContext, array $conversationState): bool
    {
        foreach ([
            'pickup_location',
            'destination',
            'departure_date',
            'departure_time',
            'passenger_count',
            'selected_seats',
        ] as $key) {
            if (! $this->isBlank($entityResult[$key] ?? null)) {
                return true;
            }
        }

        if (($conversationState['booking_expected_input'] ?? null) !== null) {
            return true;
        }

        return in_array((string) ($resolvedContext['current_topic'] ?? ''), [
            'booking_follow_up',
            'schedule_inquiry',
            'price_inquiry',
            'route_inquiry',
        ], true);
    }

    /**
     * @param  array<string, mixed>  $intentResult
     * @param  array<string, mixed>  $entityResult
     */
    private function requiresPriceRouteClarification(array $intentResult, array $entityResult): bool
    {
        $intent = (string) ($intentResult['intent'] ?? '');

        if (! in_array($intent, [IntentType::PriceInquiry->value, IntentType::TanyaHarga->value], true)) {
            return false;
        }

        return $this->isBlank($entityResult['pickup_location'] ?? null)
            || $this->isBlank($entityResult['destination'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $intentResult
     * @param  array<string, mixed>  $entityResult
     * @param  array<string, mixed>  $resolvedContext
     * @param  array<string, mixed>  $conversationState
     */
    private function clarificationQuestionFor(
        array $intentResult,
        array $entityResult,
        array $resolvedContext,
        array $conversationState,
    ): string {
        $intent = (string) ($intentResult['intent'] ?? IntentType::Unknown->value);

        return match ($intent) {
            IntentType::TanyaHarga->value, IntentType::PriceInquiry->value
                => 'Izin Bapak/Ibu, boleh kirim titik jemput dan tujuan yang ingin dicek ongkosnya?',
            IntentType::TanyaJam->value, IntentType::ScheduleInquiry->value, IntentType::TanyaKeberangkatanHariIni->value
                => 'Izin Bapak/Ibu, boleh kirim rute atau tujuan yang ingin dicek jadwalnya?',
            IntentType::TanyaRute->value, IntentType::LocationInquiry->value
                => 'Izin Bapak/Ibu, titik jemput atau tujuan mana yang ingin dicek rutenya?',
            IntentType::Booking->value
                => $this->bookingClarificationQuestion($entityResult, $resolvedContext, $conversationState),
            default
                => 'Izin Bapak/Ibu, boleh dijelaskan lagi kebutuhan perjalanannya? Misalnya rute, tanggal, atau jadwal yang ingin dicek.',
        };
    }

    /**
     * @param  array<string, mixed>  $entityResult
     * @param  array<string, mixed>  $resolvedContext
     * @param  array<string, mixed>  $conversationState
     */
    private function bookingClarificationQuestion(
        array $entityResult,
        array $resolvedContext,
        array $conversationState,
    ): string {
        if ($this->isBlank($entityResult['pickup_location'] ?? null) && $this->isBlank($entityResult['destination'] ?? null)) {
            return 'Izin Bapak/Ibu, boleh kirim dulu titik jemput dan tujuan perjalanannya ya?';
        }

        if (
            $this->isBlank($entityResult['departure_date'] ?? null)
            && $this->isBlank($entityResult['departure_time'] ?? null)
        ) {
            return 'Izin Bapak/Ibu, tanggal dan jam keberangkatannya yang diinginkan kapan ya?';
        }

        if (($conversationState['booking_expected_input'] ?? null) === 'selected_seats') {
            return 'Izin Bapak/Ibu, seat yang diinginkan yang mana ya?';
        }

        if (($resolvedContext['context_dependency_detected'] ?? false) === true) {
            return 'Izin Bapak/Ibu, boleh lanjutkan detail perjalanannya supaya kami cek dengan tepat?';
        }

        return 'Izin Bapak/Ibu, boleh kirim detail perjalanan yang ingin dibantu ya?';
    }

    /**
     * @param  array<string, mixed>  $intentResult
     * @return array<string, mixed>
     */
    private function handoffIntent(array $intentResult, string $reasoning): array
    {
        if (! isset($intentResult['intent']) || ! is_string($intentResult['intent']) || trim($intentResult['intent']) === '') {
            $intentResult['intent'] = IntentType::HumanHandoff->value;
        }

        $intentResult['confidence'] = max((float) ($intentResult['confidence'] ?? 0.0), 0.95);
        $intentResult['handoff_recommended'] = true;
        $intentResult['needs_human_review'] = true;
        $intentResult['needs_clarification'] = false;
        $intentResult['clarification_question'] = null;
        $intentResult['reasoning_short'] = $reasoning;

        return $intentResult;
    }

    /**
     * @param  array<string, mixed>  $intentResult
     */
    private function isSensitiveIntent(array $intentResult): bool
    {
        $intent = strtolower((string) ($intentResult['intent'] ?? ''));

        return in_array($intent, [
            'complaint',
            'refund_request',
            'refund',
            'legal_issue',
            'legal',
            'threat',
            'abuse_report',
            'privacy_request',
            'charge_dispute',
            IntentType::HumanHandoff->value,
        ], true);
    }

    /**
     * @param  array<string, mixed>  $crmBusinessFlags
     * @param  array<string, mixed>  $crmEscalation
     * @param  array<string, mixed>  $crmLeadPipeline
     * @return array<string, mixed>
     */
    private function crmPolicySnapshot(
        array $crmBusinessFlags,
        array $crmEscalation,
        array $crmLeadPipeline,
    ): array {
        return [
            'bot_paused' => (bool) ($crmBusinessFlags['bot_paused'] ?? false),
            'admin_takeover_active' => (bool) ($crmBusinessFlags['admin_takeover_active'] ?? false),
            'needs_human_followup' => (bool) ($crmBusinessFlags['needs_human_followup'] ?? false),
            'open_escalation' => (bool) ($crmEscalation['has_open_escalation'] ?? false),
            'lead_stage' => $crmLeadPipeline['stage'] ?? null,
        ];
    }

    private function normalizeText(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    private function isGenericClarificationQuestion(string $question): bool
    {
        return in_array(
            mb_strtolower(trim($question), 'UTF-8'),
            [
                'boleh dijelaskan lagi kebutuhan perjalanannya?',
                'boleh dijelaskan lagi kebutuhan perjalanannya',
            ],
            true,
        );
    }

    private function isBlank(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_string($value)) {
            return trim($value) === '';
        }

        if (is_array($value)) {
            return $value === [];
        }

        return false;
    }

    /**
     * @param  array<int, string>  $reasons
     * @param  array<string, mixed>  $crmBusinessFlags
     * @param  array<string, mixed>  $crmEscalation
     * @param  array<string, mixed>  $crmLeadPipeline
     * @return array<string, mixed>
     */
    private function decisionTracePolicy(
        string $traceId,
        string $action,
        array $reasons,
        array $crmBusinessFlags,
        array $crmEscalation,
        array $crmLeadPipeline,
        ?string $crmSource = null,
    ): array {
        $normalizedReasons = array_values(array_unique(array_filter(array_map(
            static fn (mixed $reason): string => trim((string) $reason),
            $reasons,
        ))));

        return [
            'trace_id' => $traceId,
            'policy' => [
                'stage' => 'policy_guard',
                'action' => $action,
                'blocked' => str_starts_with($action, 'blocked_'),
                'force_handoff' => in_array($action, ['handoff', 'clarify', 'blocked_takeover', 'blocked_admin_takeover', 'blocked_bot_paused'], true),
                'reasons' => $normalizedReasons,
                'reason_code' => $normalizedReasons[0] ?? null,
                'crm_policy_source' => $crmSource,
                'crm_policy_snapshot' => $this->crmPolicySnapshot(
                    crmBusinessFlags: $crmBusinessFlags,
                    crmEscalation: $crmEscalation,
                    crmLeadPipeline: $crmLeadPipeline,
                ),
                'evaluated_at' => now()->toIso8601String(),
            ],
            'outcome' => [
                'handoff' => in_array($action, ['handoff', 'clarify', 'blocked_takeover', 'blocked_admin_takeover', 'blocked_bot_paused'], true),
                'reply_action' => $action === 'clarify' ? 'ask_clarification' : ($action === 'allow' ? 'allow' : 'handoff_admin'),
            ],
        ];
    }

    private function resolveTraceId(array ...$sources): string
    {
        foreach ($sources as $source) {
            foreach ([
                $source['trace_id'] ?? null,
                $source['decision_trace']['trace_id'] ?? null,
                $source['meta']['trace_id'] ?? null,
                $source['job_trace_id'] ?? null,
            ] as $candidate) {
                if (is_scalar($candidate) && trim((string) $candidate) !== '') {
                    return trim((string) $candidate);
                }
            }
        }

        return 'trace-'.now()->format('YmdHis').'-'.substr(md5((string) microtime(true)), 0, 8);
    }
}
