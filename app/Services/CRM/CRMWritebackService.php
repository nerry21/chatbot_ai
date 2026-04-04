<?php

namespace App\Services\CRM;

use App\Enums\IntentType;
use App\Jobs\EscalateConversationToAdminJob;
use App\Jobs\SyncContactToCrmJob;
use App\Jobs\SyncConversationSummaryToCrmJob;
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
    ) {}

    /**
     * Tulis balik hasil keputusan AI ke CRM dalam satu alur terpadu.
     *
     * Dengan ini, LLM tidak hanya "membaca CRM", tetapi juga
     * mengembalikan hasil reasoning operasional ke CRM.
     *
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
                'reason' => 'conversation_has_no_customer',
            ];
        }

        $intent = is_string($intentResult['intent'] ?? null)
            ? (string) $intentResult['intent']
            : null;

        $intentEnum = $intent !== null ? IntentType::tryFrom($intent) : null;

        $tags = $this->contactTaggingService->applyBasicTags(
            customer: $customer,
            booking: $booking,
            intent: $intent,
        );

        $lead = $this->leadPipelineService->syncFromContext(
            customer: $customer,
            conversation: $conversation,
            booking: $booking,
            intent: $intent,
        );

        SyncContactToCrmJob::dispatch($customer->id);
        $contactSync = ['status' => 'queued'];

        $summarySync = ['status' => 'skipped', 'reason' => 'no_summary'];
        if ($this->shouldSyncSummary($summaryResult)) {
            SyncConversationSummaryToCrmJob::dispatch($customer->id, $conversation->id);
            $summarySync = ['status' => 'queued'];
        }

        $decisionNoteSync = ['status' => 'skipped', 'reason' => 'no_decision_note'];
        $decisionNote = $this->decisionNoteBuilder->build(
            customer: $customer,
            conversation: $conversation,
            intentResult: $intentResult,
            summaryResult: $summaryResult,
            finalReply: $finalReply,
            contextSnapshot: $contextSnapshot,
        );

        if (trim($decisionNote) !== '') {
            $decisionNoteSync = $this->crmSyncService->appendConversationDecisionNote(
                customer: $customer,
                note: $decisionNote,
            );
        }

        $needsEscalation = (bool) $conversation->needs_human
            || ($intentEnum !== null && $intentEnum->requiresHuman())
            || (($finalReply['meta']['force_handoff'] ?? false) === true)
            || (($summaryResult['needs_human_followup'] ?? false) === true);

        if ($needsEscalation) {
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
            'lead_stage' => $lead?->stage,
            'tags' => $tags,
            'contact_sync' => $contactSync['status'] ?? null,
            'summary_sync' => $summarySync['status'] ?? null,
            'decision_note_sync' => $decisionNoteSync['status'] ?? null,
            'needs_escalation' => $needsEscalation,
            'crm_context_present' => ! empty($contextSnapshot['crm_context']),
            'orchestration_present' => ! empty($contextSnapshot['orchestration']),
            'final_reply_source' => $finalReply['meta']['source'] ?? null,
            'final_reply_grounding_source' => $finalReply['meta']['grounding_source'] ?? null,
            'final_reply_force_handoff' => $finalReply['meta']['force_handoff'] ?? false,
        ]);

        return [
            'status' => 'ok',
            'tags' => $tags,
            'lead_stage' => $lead?->stage,
            'lead_id' => $lead?->id,
            'contact_sync' => $contactSync,
            'summary_sync' => $summarySync,
            'decision_note_sync' => $decisionNoteSync,
            'needs_escalation' => $needsEscalation,
            'context_snapshot' => [
                'crm_context_present' => ! empty($contextSnapshot['crm_context']),
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
        $summary = trim((string) ($summaryResult['summary'] ?? ''));

        return $summary !== '';
    }
}
