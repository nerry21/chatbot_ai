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

        $snapshot = [
            'snapshot_version' => 2,
            'generated_at' => now()->toIso8601String(),

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
            ]),

            'ai_decision' => $this->clean([
                'intent' => $intentResult['intent'] ?? null,
                'confidence' => isset($intentResult['confidence']) ? (float) $intentResult['confidence'] : null,
                'reasoning_short' => $intentResult['reasoning_short'] ?? null,
                'needs_clarification' => (bool) ($intentResult['needs_clarification'] ?? false),
                'clarification_question' => $intentResult['clarification_question'] ?? null,
                'handoff_recommended' => (bool) ($intentResult['handoff_recommended'] ?? false),
                'entity_result' => $entityResult,
                'understanding_meta' => $understandingMeta,
                'decision_trace' => $decisionTrace,
            ]),
        ];

        return $this->clean($snapshot);
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
