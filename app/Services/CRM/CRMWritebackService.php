<?php

namespace App\Services\CRM;

use App\Enums\IntentType;
use App\Jobs\EscalateConversationToAdminJob;
use App\Models\BookingRequest;
use App\Models\Conversation;
use App\Support\WaLog;

class CRMWritebackService
{
    public function __construct(
        private readonly ContactTaggingService $contactTaggingService,
        private readonly LeadPipelineService $leadPipelineService,
        private readonly CrmSyncService $crmSyncService,
        private readonly CrmDecisionNoteBuilderService $decisionNoteBuilder,
        private readonly CrmDecisionTraceBuilderService $decisionTraceBuilder,
    ) {}

    /**
     * @param  array<string, mixed>  $intentResult
     * @param  array<string, mixed>  $summaryResult
     * @param  array<string, mixed>  $finalReply
     * @param  array<string, mixed>  $contextSnapshot
     * @return array<string, mixed>
     */
    public function syncDecision(
        Conversation $conversation,
        ?BookingRequest $booking,
        array $intentResult,
        array $summaryResult,
        array $finalReply,
        array $contextSnapshot = [],
    ): array {
        $customer = $conversation->customer;

        if ($customer === null) {
            return [
                'status' => 'skipped',
                'contact_sync' => ['status' => 'skipped', 'reason' => 'missing_customer'],
                'summary_sync' => ['status' => 'skipped', 'reason' => 'missing_customer'],
                'decision_note_sync' => ['status' => 'skipped', 'reason' => 'missing_customer'],
                'lead' => null,
                'lead_stage' => null,
                'lead_id' => null,
                'tags' => [],
                'needs_escalation' => false,
            ];
        }

        $crmContext = is_array($contextSnapshot['crm_context'] ?? null)
            ? $contextSnapshot['crm_context']
            : [];

        $crmLeadPipeline = is_array($crmContext['lead_pipeline'] ?? null)
            ? $crmContext['lead_pipeline']
            : [];

        $crmConversation = is_array($crmContext['conversation'] ?? null)
            ? $crmContext['conversation']
            : [];

        $crmEscalation = is_array($crmContext['escalation'] ?? null)
            ? $crmContext['escalation']
            : [];

        $builderContext = $contextSnapshot;
        if (is_array($contextSnapshot['decision_trace'] ?? null)) {
            $builderContext = array_merge($builderContext, (array) $contextSnapshot['decision_trace']);
        }

        $decisionTrace = $this->decisionTraceBuilder->build(
            customer: $customer,
            conversation: $conversation,
            intentResult: $intentResult,
            summaryResult: $summaryResult,
            finalReply: $finalReply,
            contextSnapshot: $builderContext,
        );

        $needsEscalation = $this->needsEscalation(
            conversation: $conversation,
            intentResult: $intentResult,
            summaryResult: $summaryResult,
            finalReply: $finalReply,
            crmConversation: $crmConversation,
            crmEscalation: $crmEscalation,
        );

        $summarySync = ['status' => 'skipped', 'reason' => 'no_summary'];
        if ($this->shouldSyncSummary($summaryResult)) {
            $summarySync = $this->crmSyncService->syncConversationSummary(
                customer: $customer,
                conversation: $conversation,
            );
        }

        $decisionNote = $this->decisionNoteBuilder->build(
            customer: $customer,
            conversation: $conversation,
            intentResult: $intentResult,
            summaryResult: $summaryResult,
            finalReply: $finalReply,
            contextSnapshot: $contextSnapshot,
            decisionTrace: $decisionTrace,
        );

        $decisionNoteSync = ['status' => 'skipped', 'reason' => 'no_decision_note'];
        if (trim($decisionNote) !== '') {
            $decisionNoteSync = $this->crmSyncService->appendConversationDecisionNote(
                customer: $customer,
                note: $decisionNote,
                decisionTrace: $decisionTrace,
            );
        }

        $intent = is_string($intentResult['intent'] ?? null)
            ? trim((string) $intentResult['intent'])
            : 'unknown';

        $tags = $this->applyDecisionTags(
            customer: $customer,
            booking: $booking,
            intent: $intent,
            crmContext: $crmContext,
            intentResult: $intentResult,
            summaryResult: $summaryResult,
            finalReply: $finalReply,
            needsEscalation: $needsEscalation,
        );

        $lead = $this->syncLeadFromDecision(
            customer: $customer,
            conversation: $conversation,
            booking: $booking,
            intent: $intent,
            crmLeadPipeline: $crmLeadPipeline,
            intentResult: $intentResult,
            summaryResult: $summaryResult,
            finalReply: $finalReply,
            needsEscalation: $needsEscalation,
        );

        $contactSync = $this->crmSyncService->syncCustomerSnapshot(
            customer: $customer,
            context: [
                'last_ai_intent' => $intentResult['intent'] ?? null,
                'last_ai_summary' => $summaryResult['summary'] ?? null,
                'customer_interest_topic' => $this->deriveInterestTopic($intentResult, $summaryResult, $finalReply),
                'ai_sentiment' => $summaryResult['sentiment'] ?? null,
                'needs_human_followup' => $needsEscalation,
                'admin_takeover_active' => (bool) ($crmContext['business_flags']['admin_takeover_active'] ?? false),
                'last_whatsapp_interaction_at' => now()->toIso8601String(),
            ],
        );

        if ($needsEscalation && (($crmEscalation['has_open_escalation'] ?? false) !== true)) {
            EscalateConversationToAdminJob::dispatch(
                $conversation->id,
                (string) ($intentResult['reasoning_short'] ?? $intent ?? 'AI requested escalation'),
                'normal',
            );
        }

        WaLog::info('[CRMWriteback] CRM + LLM writeback complete', [
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'intent' => $intent,
            'lead_stage_before' => $crmLeadPipeline['stage'] ?? null,
            'lead_stage_after' => $lead?->stage,
            'tags' => $tags,
            'contact_sync' => $contactSync['status'] ?? null,
            'summary_sync' => $summarySync['status'] ?? null,
            'decision_note_sync' => $decisionNoteSync['status'] ?? null,
            'needs_escalation' => $needsEscalation,
            'decision_trace_id' => $decisionTrace['trace_id'] ?? null,
            'decision_trace_final_decision' => $decisionTrace['outcome']['final_decision'] ?? null,
            'decision_trace_used_crm_facts' => $decisionTrace['outcome']['used_crm_facts'] ?? [],
        ]);

        return [
            'status' => 'ok',
            'tags' => $tags,
            'lead_stage' => $lead?->stage,
            'lead_id' => $lead?->id,
            'contact_sync' => $contactSync,
            'summary_sync' => $summarySync,
            'decision_note_sync' => $decisionNoteSync,
            'lead' => $lead,
            'needs_escalation' => $needsEscalation,
            'decision_trace' => $decisionTrace,
            'context_snapshot' => [
                'crm_context_present' => ! empty($crmContext),
                'orchestration_present' => ! empty($contextSnapshot['orchestration']),
                'conversation_id' => $conversation->id,
                'customer_id' => $customer->id,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $summaryResult
     */
    private function shouldSyncSummary(array $summaryResult): bool
    {
        return trim((string) ($summaryResult['summary'] ?? '')) !== '';
    }

    /**
     * @param  array<string, mixed>  $crmContext
     * @param  array<string, mixed>  $intentResult
     * @param  array<string, mixed>  $summaryResult
     * @param  array<string, mixed>  $finalReply
     * @return array<int, string>
     */
    private function applyDecisionTags(
        $customer,
        ?BookingRequest $booking,
        string $intent,
        array $crmContext,
        array $intentResult,
        array $summaryResult,
        array $finalReply,
        bool $needsEscalation,
    ): array {
        $tags = $this->contactTaggingService->applyBasicTags(
            customer: $customer,
            booking: $booking,
            intent: $intent !== '' ? $intent : null,
        );

        $extraTags = [];
        $leadStage = $crmContext['lead_pipeline']['stage'] ?? null;
        $businessFlags = is_array($crmContext['business_flags'] ?? null) ? $crmContext['business_flags'] : [];
        $escalation = is_array($crmContext['escalation'] ?? null) ? $crmContext['escalation'] : [];

        if ($needsEscalation) {
            $extraTags[] = 'needs_human_followup';
        }

        if (($escalation['has_open_escalation'] ?? false) === true) {
            $extraTags[] = 'open_escalation';
        }

        if (($businessFlags['admin_takeover_active'] ?? false) === true) {
            $extraTags[] = 'admin_takeover_active';
        }

        if (($businessFlags['bot_paused'] ?? false) === true) {
            $extraTags[] = 'bot_paused';
        }

        if (in_array($leadStage, ['complaint', 'high_risk', 'refund', 'legal'], true)) {
            $extraTags[] = 'high_risk_case';
        }

        if (($intentResult['needs_clarification'] ?? false) === true) {
            $extraTags[] = 'needs_clarification';
        }

        if (($finalReply['is_fallback'] ?? false) === true) {
            $extraTags[] = 'ai_fallback';
        }

        if (trim((string) ($summaryResult['summary'] ?? '')) !== '') {
            $extraTags[] = 'conversation_summarized';
        }

        return array_values(array_unique(array_merge($tags, $extraTags)));
    }

    /**
     * @param  array<string, mixed>  $crmLeadPipeline
     * @param  array<string, mixed>  $intentResult
     * @param  array<string, mixed>  $summaryResult
     * @param  array<string, mixed>  $finalReply
     */
    private function syncLeadFromDecision(
        $customer,
        Conversation $conversation,
        ?BookingRequest $booking,
        string $intent,
        array $crmLeadPipeline,
        array $intentResult,
        array $summaryResult,
        array $finalReply,
        bool $needsEscalation,
    ): mixed {
        $lead = $this->leadPipelineService->syncFromContext(
            customer: $customer,
            conversation: $conversation,
            booking: $booking,
            intent: $intent !== '' ? $intent : null,
        );

        if ($lead === null) {
            return null;
        }

        if ($needsEscalation && ! in_array($lead->stage, ['complaint', 'cancelled', 'completed', 'paid'], true)) {
            $lead = $this->leadPipelineService->moveToStage($lead, 'complaint');
        }

        return $lead;
    }

    /**
     * @param  array<string, mixed>  $intentResult
     * @param  array<string, mixed>  $summaryResult
     * @param  array<string, mixed>  $finalReply
     * @param  array<string, mixed>  $crmConversation
     * @param  array<string, mixed>  $crmEscalation
     */
    private function needsEscalation(
        Conversation $conversation,
        array $intentResult,
        array $summaryResult,
        array $finalReply,
        array $crmConversation = [],
        array $crmEscalation = [],
    ): bool {
        $intent = is_string($intentResult['intent'] ?? null)
            ? trim((string) $intentResult['intent'])
            : '';

        $intentEnum = $intent !== '' ? IntentType::tryFrom($intent) : null;

        return
            (bool) ($intentResult['handoff_recommended'] ?? false)
            || (bool) ($intentResult['needs_human_review'] ?? false)
            || (bool) ($crmEscalation['has_open_escalation'] ?? false)
            || (bool) ($crmConversation['needs_human'] ?? false)
            || ($intentEnum !== null && $intentEnum->requiresHuman())
            || (($finalReply['meta']['force_handoff'] ?? false) === true)
            || (bool) ($summaryResult['needs_human_followup'] ?? false)
            || $conversation->isAdminTakeover();
    }

    /**
     * @param  array<string, mixed>  $intentResult
     * @param  array<string, mixed>  $summaryResult
     * @param  array<string, mixed>  $finalReply
     */
    private function deriveInterestTopic(array $intentResult, array $summaryResult, array $finalReply): ?string
    {
        foreach ([
            $summaryResult['topic'] ?? null,
            $summaryResult['customer_interest_topic'] ?? null,
            $intentResult['sub_intent'] ?? null,
            $intentResult['intent'] ?? null,
            $finalReply['meta']['action'] ?? null,
        ] as $candidate) {
            if (! is_scalar($candidate)) {
                continue;
            }

            $text = trim((string) $candidate);
            if ($text !== '') {
                return $text;
            }
        }

        return null;
    }
}
