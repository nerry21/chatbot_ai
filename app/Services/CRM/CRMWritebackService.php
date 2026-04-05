<?php

namespace App\Services\CRM;

use App\Enums\IntentType;
use App\Jobs\EscalateConversationToAdminJob;
use App\Models\BookingRequest;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Escalation;
use App\Services\CRM\CrmDecisionTraceBuilderService;
use App\Support\WaLog;
use Illuminate\Support\Facades\DB;

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

        $intent = is_string($intentResult['intent'] ?? null)
            ? trim((string) $intentResult['intent'])
            : 'unknown';

        $intentEnum = $intent !== '' ? IntentType::tryFrom($intent) : null;
        $leadStageBefore = $crmLeadPipeline['stage'] ?? null;

        $contactSync = $this->crmSyncService->syncCustomerSnapshot(
            customer: $customer,
            context: [
                'last_ai_intent' => $intentResult['intent'] ?? null,
                'last_ai_summary' => $summaryResult['summary'] ?? null,
                'customer_interest_topic' => $this->deriveInterestTopic($intentResult, $summaryResult, $finalReply),
                'ai_sentiment' => $summaryResult['sentiment'] ?? null,
                'needs_human_followup' => false,
                'admin_takeover_active' => (bool) ($crmContext['business_flags']['admin_takeover_active'] ?? false),
                'last_whatsapp_interaction_at' => now()->toIso8601String(),
            ],
        );

        $summarySync = ['status' => 'skipped', 'reason' => 'no_summary'];
        if ($this->shouldSyncSummary($summaryResult)) {
            $summarySync = $this->crmSyncService->syncConversationSummary(
                customer: $customer,
                conversation: $conversation,
            );
        }

        $decisionTrace = $this->decisionTraceBuilder->build(
            customer: $customer,
            conversation: $conversation,
            intentResult: $intentResult,
            summaryResult: $summaryResult,
            finalReply: $finalReply,
            contextSnapshot: $contextSnapshot,
        );

        $decisionNoteSync = ['status' => 'skipped', 'reason' => 'no_decision_note'];
        $decisionNote = $this->decisionNoteBuilder->build(
            customer: $customer,
            conversation: $conversation,
            intentResult: $intentResult,
            summaryResult: $summaryResult,
            finalReply: $finalReply,
            contextSnapshot: $contextSnapshot,
            decisionTrace: $decisionTrace,
        );

        if (trim($decisionNote) !== '') {
            $decisionNoteSync = $this->crmSyncService->appendConversationDecisionNote(
                customer: $customer,
                note: $decisionNote,
                decisionTrace: $decisionTrace,
            );
        }

        $needsEscalation =
            (bool) ($intentResult['handoff_recommended'] ?? false)
            || (bool) ($intentResult['needs_human_review'] ?? false)
            || (bool) ($crmEscalation['has_open_escalation'] ?? false)
            || (bool) ($crmConversation['needs_human'] ?? false)
            || ($intentEnum !== null && $intentEnum->requiresHuman())
            || (($finalReply['meta']['force_handoff'] ?? false) === true)
            || (($summaryResult['needs_human_followup'] ?? false) === true);

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

        if ($needsEscalation) {
            $conversation->forceFill([
                'needs_human' => true,
            ])->save();

            try {
                EscalateConversationToAdminJob::dispatch(
                    $conversation->id,
                    (string) ($intentResult['reasoning_short'] ?? $intent ?? 'AI requested escalation'),
                    'normal',
                );
            } catch (\Throwable $e) {
                WaLog::error('[CRMWriteback] Escalation dispatch failed - applying inline fallback', [
                    'conversation_id' => $conversation->id,
                    'error' => $e->getMessage(),
                ]);

                $existingEscalation = Escalation::where('conversation_id', $conversation->id)
                    ->where('status', 'open')
                    ->first();

                if ($existingEscalation === null) {
                    Escalation::create([
                        'conversation_id' => $conversation->id,
                        'reason' => (string) ($intentResult['reasoning_short'] ?? $intent ?? 'AI requested escalation'),
                        'priority' => 'normal',
                        'status' => 'open',
                        'summary' => $conversation->summary,
                    ]);
                }
            }
        }

        WaLog::info('[CRMWriteback] CRM + LLM writeback complete', [
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'intent' => $intent,
            'lead_stage_before' => $leadStageBefore,
            'lead_stage_after' => $lead?->stage,
            'tags' => $tags,
            'contact_sync' => $contactSync['status'] ?? null,
            'summary_sync' => $summarySync['status'] ?? null,
            'decision_note_sync' => $decisionNoteSync['status'] ?? null,
            'needs_escalation' => $needsEscalation,
            'crm_snapshot_present' => ! empty($crmContext),
            'orchestration_present' => ! empty($contextSnapshot['orchestration']),
            'final_reply_source' => $finalReply['meta']['source'] ?? null,
            'final_reply_grounding_source' => $finalReply['meta']['grounding_source'] ?? null,
            'final_reply_force_handoff' => $finalReply['meta']['force_handoff'] ?? false,
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
        $summary = trim((string) ($summaryResult['summary'] ?? ''));

        return $summary !== '';
    }

    /**
     * @param  array<string, mixed>  $crmContext
     * @param  array<string, mixed>  $intentResult
     * @param  array<string, mixed>  $summaryResult
     * @param  array<string, mixed>  $finalReply
     * @return array<int, string>
     */
    private function applyDecisionTags(
        Customer $customer,
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

        if (in_array($leadStage, ['complaint', 'refund', 'legal', 'high_risk'], true)) {
            $extraTags[] = 'crm_high_risk_case';
        }

        if (($intentResult['handoff_recommended'] ?? false) === true || (($finalReply['meta']['force_handoff'] ?? false) === true)) {
            $extraTags[] = 'human_handoff';
        }

        $interestTopic = $this->deriveInterestTopic($intentResult, $summaryResult, $finalReply);
        if ($interestTopic !== null) {
            $extraTags[] = 'interest_'.str_replace([' ', ':'], '_', strtolower($interestTopic));
        }

        foreach (array_values(array_unique(array_filter($extraTags))) as $tag) {
            DB::table('customer_tags')->insertOrIgnore([
                'customer_id' => $customer->id,
                'tag' => $tag,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $tags[] = $tag;
        }

        return array_values(array_unique($tags));
    }

    /**
     * @param  array<string, mixed>  $crmLeadPipeline
     * @param  array<string, mixed>  $intentResult
     * @param  array<string, mixed>  $summaryResult
     * @param  array<string, mixed>  $finalReply
     */
    private function syncLeadFromDecision(
        Customer $customer,
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

        $targetStage = $this->resolveLeadStageFromDecision(
            currentStage: $lead->stage ?? ($crmLeadPipeline['stage'] ?? null),
            intentResult: $intentResult,
            summaryResult: $summaryResult,
            finalReply: $finalReply,
            needsEscalation: $needsEscalation,
        );

        if ($targetStage !== null && $targetStage !== $lead->stage) {
            $lead = $this->leadPipelineService->moveToStage($lead, $targetStage);
        }

        return $lead;
    }

    /**
     * @param  array<string, mixed>  $intentResult
     * @param  array<string, mixed>  $summaryResult
     * @param  array<string, mixed>  $finalReply
     */
    private function resolveLeadStageFromDecision(
        ?string $currentStage,
        array $intentResult,
        array $summaryResult,
        array $finalReply,
        bool $needsEscalation,
    ): ?string {
        $intent = strtolower(trim((string) ($intentResult['intent'] ?? 'unknown')));
        $summaryIntent = strtolower(trim((string) ($summaryResult['intent'] ?? '')));
        $replyAction = strtolower(trim((string) ($finalReply['meta']['action'] ?? $finalReply['next_action'] ?? '')));

        if (in_array($intent, ['complaint', 'refund_request', 'refund', 'legal_issue', 'legal', 'threat'], true)) {
            return 'complaint';
        }

        if (in_array($summaryIntent, ['complaint', 'refund_request', 'refund', 'legal_issue', 'legal'], true)) {
            return 'complaint';
        }

        if ($needsEscalation && in_array($replyAction, ['handoff_admin', 'handoff_sensitive_request'], true)) {
            return 'complaint';
        }

        return $currentStage;
    }

    /**
     * @param  array<string, mixed>  $intentResult
     * @param  array<string, mixed>  $summaryResult
     * @param  array<string, mixed>  $finalReply
     */
    private function deriveInterestTopic(
        array $intentResult,
        array $summaryResult,
        array $finalReply,
    ): ?string {
        foreach ([
            $summaryResult['interest_topic'] ?? null,
            $summaryResult['intent'] ?? null,
            $intentResult['intent'] ?? null,
            $finalReply['next_action'] ?? null,
        ] as $candidate) {
            if (! is_scalar($candidate)) {
                continue;
            }

            $value = trim((string) $candidate);
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }
}
