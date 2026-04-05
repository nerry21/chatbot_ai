<?php

namespace App\Services\CRM;

use App\Models\BookingRequest;
use App\Models\Conversation;
use App\Models\Customer;

class CrmOrchestrationSnapshotService
{
    public function __construct(
        private readonly CRMContextService $crmContextService,
    ) {}

    /**
     * Bangun snapshot tunggal CRM + runtime conversation + hasil reasoning AI.
     *
     * @param  array<string, mixed>  $contextPayload
     * @param  array<string, mixed>  $intentResult
     * @param  array<string, mixed>  $entityResult
     * @param  array<string, mixed>|null  $bookingDecision
     * @return array<string, mixed>
     */
    public function build(
        Customer $customer,
        Conversation $conversation,
        ?BookingRequest $booking = null,
        array $contextPayload = [],
        array $intentResult = [],
        array $entityResult = [],
        ?array $bookingDecision = null,
    ): array {
        $crmContext = $this->crmContextService->build(
            customer: $customer,
            conversation: $conversation,
            booking: $booking,
        );

        $conversationState = is_array($contextPayload['conversation_state'] ?? null) ? $contextPayload['conversation_state'] : [];
        $resolvedContext = is_array($contextPayload['resolved_context'] ?? null) ? $contextPayload['resolved_context'] : [];
        $knownEntities = is_array($contextPayload['known_entities'] ?? null) ? $contextPayload['known_entities'] : [];
        $customerMemory = is_array($contextPayload['customer_memory'] ?? null) ? $contextPayload['customer_memory'] : [];
        $decisionTrace = is_array($contextPayload['decision_trace'] ?? null) ? $contextPayload['decision_trace'] : [];
        $understandingMeta = is_array($contextPayload['understanding_meta'] ?? null) ? $contextPayload['understanding_meta'] : [];

        $traceId = $this->resolveTraceId(
            $decisionTrace,
            $understandingMeta,
            $contextPayload,
        );

        $traceSummary = $this->buildTraceSummary(
            $decisionTrace,
            $intentResult,
            $understandingMeta,
        );

        $snapshot = [
            'snapshot_version' => 3,
            'generated_at' => now()->toIso8601String(),
            'trace_id' => $traceId,

            'customer' => $this->clean($crmContext['customer'] ?? []),
            'hubspot' => $this->clean($crmContext['hubspot'] ?? []),
            'lead_pipeline' => $this->clean($crmContext['lead_pipeline'] ?? []),

            'conversation' => $this->clean(array_merge(
                is_array($crmContext['conversation'] ?? null) ? $crmContext['conversation'] : [],
                [
                    'admin_takeover' => (bool) ($conversation->isAdminTakeover() ?? false),
                    'bot_paused' => (bool) ($conversation->bot_paused ?? false),
                    'needs_human' => (bool) ($conversation->needs_human ?? false),
                ],
            )),

            'booking' => $this->clean(array_merge(
                is_array($crmContext['booking'] ?? null) ? $crmContext['booking'] : [],
                $bookingDecision !== null ? ['decision' => $this->clean($bookingDecision)] : [],
            )),

            'escalation' => $this->clean($crmContext['escalation'] ?? []),

            'business_flags' => $this->clean(array_merge(
                is_array($crmContext['business_flags'] ?? null) ? $crmContext['business_flags'] : [],
                [
                    'admin_takeover_active' => (bool) ($conversation->isAdminTakeover() ?? false),
                    'bot_paused' => (bool) ($conversation->bot_paused ?? false),
                    'needs_human_followup' => (bool) ($conversation->needs_human ?? false)
                        || ((bool) (($crmContext['escalation']['has_open_escalation'] ?? false) === true)),
                ],
            )),

            'runtime' => $this->clean([
                'conversation_state' => $conversationState,
                'resolved_context' => $resolvedContext,
                'known_entities' => $knownEntities,
                'customer_memory' => $customerMemory,
                'latest_summary' => $contextPayload['conversation_summary'] ?? null,
                'latest_message_text' => $contextPayload['latest_message_text'] ?? ($contextPayload['message_text'] ?? null),
                'admin_takeover' => (bool) ($contextPayload['admin_takeover'] ?? false),
                'message_id' => $contextPayload['message_id'] ?? null,
                'conversation_id' => $conversation->id,
                'job_trace_id' => $contextPayload['job_trace_id'] ?? null,
            ]),

            'ai_decision' => $this->clean([
                'trace_id' => $traceId,
                'intent' => $intentResult['intent'] ?? null,
                'confidence' => isset($intentResult['confidence']) ? (float) $intentResult['confidence'] : null,
                'reasoning_short' => $intentResult['reasoning_short'] ?? null,
                'needs_clarification' => (bool) ($intentResult['needs_clarification'] ?? false),
                'clarification_question' => $intentResult['clarification_question'] ?? null,
                'handoff_recommended' => (bool) ($intentResult['handoff_recommended'] ?? false),
                'entity_result' => $entityResult,
                'understanding_meta' => $understandingMeta,
                'trace_summary' => $traceSummary,
                'decision_trace' => $decisionTrace,
            ]),
        ];

        return $this->clean($snapshot);
    }

    /**
     * @param  array<string, mixed>  $decisionTrace
     * @param  array<string, mixed>  $understandingMeta
     * @param  array<string, mixed>  $contextPayload
     */
    private function resolveTraceId(
        array $decisionTrace,
        array $understandingMeta,
        array $contextPayload = [],
    ): string {
        foreach ([
            $decisionTrace['trace_id'] ?? null,
            $understandingMeta['trace_id'] ?? null,
            $contextPayload['trace_id'] ?? null,
            $contextPayload['job_trace_id'] ?? null,
        ] as $candidate) {
            if (is_scalar($candidate) && trim((string) $candidate) !== '') {
                return trim((string) $candidate);
            }
        }

        return 'trace-'.now()->format('YmdHis').'-'.substr(md5((string) microtime(true)), 0, 8);
    }

    /**
     * @param  array<string, mixed>  $decisionTrace
     * @param  array<string, mixed>  $intentResult
     * @param  array<string, mixed>  $understandingMeta
     * @return array<string, mixed>
     */
    private function buildTraceSummary(
        array $decisionTrace,
        array $intentResult,
        array $understandingMeta = [],
    ): array {
        $outcome = is_array($decisionTrace['outcome'] ?? null) ? $decisionTrace['outcome'] : [];
        $policy = is_array($decisionTrace['policy'] ?? null) ? $decisionTrace['policy'] : [];
        $grounding = is_array($decisionTrace['grounding'] ?? null) ? $decisionTrace['grounding'] : [];

        return $this->clean([
            'trace_id' => $decisionTrace['trace_id'] ?? $understandingMeta['trace_id'] ?? null,
            'intent' => $intentResult['intent'] ?? null,
            'final_decision' => $outcome['final_decision'] ?? null,
            'reply_action' => $outcome['reply_action'] ?? null,
            'handoff' => $outcome['handoff'] ?? null,
            'grounded' => $grounding['grounded'] ?? null,
            'policy_status' => $policy['status'] ?? null,
            'policy_reason_code' => $policy['reason_code'] ?? null,
            'understanding_model' => $understandingMeta['model'] ?? null,
            'understanding_mode' => $understandingMeta['mode'] ?? null,
        ]);
    }

    /**
     * @return mixed
     */
    private function clean(mixed $value): mixed
    {
        if (is_array($value)) {
            $cleaned = [];

            foreach ($value as $key => $item) {
                $normalized = $this->clean($item);

                if ($normalized === null) {
                    continue;
                }

                if (is_string($normalized) && trim($normalized) === '') {
                    continue;
                }

                if (is_array($normalized) && $normalized === []) {
                    continue;
                }

                $cleaned[$key] = $normalized;
            }

            return $cleaned;
        }

        return $value;
    }
}
