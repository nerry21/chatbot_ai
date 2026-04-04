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
        if (! empty($summaryResult['summary'])) {
            SyncConversationSummaryToCrmJob::dispatch($customer->id, $conversation->id);
            $summarySync = ['status' => 'queued'];
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
            'needs_escalation' => $needsEscalation,
            'crm_context_present' => ! empty($contextSnapshot['crm_context']),
            'orchestration_present' => ! empty($contextSnapshot['orchestration']),
        ]);

        return [
            'status' => 'ok',
            'tags' => $tags,
            'lead_stage' => $lead?->stage,
            'lead_id' => $lead?->id,
            'contact_sync' => $contactSync,
            'summary_sync' => $summarySync,
            'needs_escalation' => $needsEscalation,
            'context_snapshot' => [
                'crm_context_present' => ! empty($contextSnapshot['crm_context']),
                'orchestration_present' => ! empty($contextSnapshot['orchestration']),
                'conversation_id' => $conversation->id,
                'customer_id' => $customer->id,
            ],
        ];
    }
}
