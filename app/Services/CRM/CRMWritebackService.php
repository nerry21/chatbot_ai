<?php

namespace App\Services\CRM;

use App\Enums\IntentType;
use App\Jobs\EscalateConversationToAdminJob;
use App\Jobs\RetryDecisionNoteToCrmJob;
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

        $llmRuntime = $this->resolveLlmRuntimeBundle(
            contextSnapshot: $contextSnapshot,
            intentResult: $intentResult,
            finalReply: $finalReply,
        );

        $runtimeHealth = $this->resolveRuntimeHealthFromBundle($llmRuntime);

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

        $summarySync = $this->runSummarySyncStep(
            customer: $customer,
            conversation: $conversation,
            summaryResult: $summaryResult,
        );

        $decisionNote = $this->decisionNoteBuilder->build(
            customer: $customer,
            conversation: $conversation,
            intentResult: $intentResult,
            summaryResult: $summaryResult,
            finalReply: $finalReply,
            contextSnapshot: $contextSnapshot,
            decisionTrace: $decisionTrace,
        );

        $decisionNoteSync = $this->runDecisionNoteSyncStep(
            customer: $customer,
            decisionNote: $decisionNote,
            decisionTrace: $decisionTrace,
            runtimeHealth: $runtimeHealth,
            llmRuntime: $llmRuntime,
        );

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

        $leadStep = $this->runLeadSyncStep(
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

        $lead = $leadStep['lead'];

        $contactSync = $this->runContactSyncStep(
            customer: $customer,
            intentResult: $intentResult,
            summaryResult: $summaryResult,
            finalReply: $finalReply,
            crmContext: $crmContext,
            needsEscalation: $needsEscalation,
            runtimeHealth: $runtimeHealth,
            llmRuntime: $llmRuntime,
        );

        $escalationSync = $this->runEscalationSyncStep(
            conversation: $conversation,
            intentResult: $intentResult,
            intent: $intent,
            needsEscalation: $needsEscalation,
            crmEscalation: $crmEscalation,
        );

        $writebackReport = [
            'contact_sync' => $contactSync,
            'summary_sync' => $summarySync,
            'decision_note_sync' => $decisionNoteSync,
            'lead_sync' => $leadStep['report'],
            'escalation_sync' => $escalationSync,
        ];

        WaLog::info('[CRMWriteback] CRM + LLM writeback complete', [
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'intent' => $intent,
            'lead_stage_before' => $crmLeadPipeline['stage'] ?? null,
            'lead_stage_after' => $lead?->stage,
            'tags' => $tags,
            'contact_sync' => $contactSync,
            'summary_sync' => $summarySync,
            'decision_note_sync' => $decisionNoteSync,
            'lead_sync' => $leadStep['report'],
            'escalation_sync' => $escalationSync,
            'writeback_report' => $writebackReport,
            'needs_escalation' => $needsEscalation,
            'decision_trace_id' => $decisionTrace['trace_id'] ?? null,
            'decision_trace_final_decision' => $decisionTrace['outcome']['final_decision'] ?? null,
            'decision_trace_used_crm_facts' => $decisionTrace['outcome']['used_crm_facts'] ?? [],
            'runtime_health' => $runtimeHealth,
            'runtime_summary' => $this->summarizeRuntimeForWriteback($llmRuntime),
        ]);

        return [
            'status' => 'ok',
            'tags' => $tags,
            'lead_stage' => $lead?->stage,
            'lead_id' => $lead?->id,
            'contact_sync' => $contactSync,
            'summary_sync' => $summarySync,
            'decision_note_sync' => $decisionNoteSync,
            'lead_sync' => $leadStep['report'],
            'escalation_sync' => $escalationSync,
            'writeback_report' => $writebackReport,
            'lead' => $lead,
            'needs_escalation' => $needsEscalation,
            'decision_trace' => $decisionTrace,
            'context_snapshot' => [
                'crm_context_present' => ! empty($crmContext),
                'orchestration_present' => ! empty($contextSnapshot['orchestration']),
                'conversation_id' => $conversation->id,
                'customer_id' => $customer->id,
                'runtime_health' => $runtimeHealth,
                'runtime_summary' => $this->summarizeRuntimeForWriteback($llmRuntime),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $summaryResult
     * @return array<string, mixed>
     */
    private function runSummarySyncStep(
        $customer,
        Conversation $conversation,
        array $summaryResult,
    ): array {
        if (! $this->shouldSyncSummary($summaryResult)) {
            return [
                'status' => 'skipped',
                'reason' => 'no_summary',
                'action' => 'summary_sync',
            ];
        }

        SyncConversationSummaryToCrmJob::dispatch(
            customerId: $customer->id,
            conversationId: $conversation->id,
        )->onQueue('crm');

        return [
            'status' => 'queued',
            'queue' => 'crm',
            'reason' => 'summary_sync_enqueued',
            'action' => 'summary_sync',
            'metadata' => [
                'customer_id' => $customer->id,
                'conversation_id' => $conversation->id,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $decisionTrace
     * @param  array<string, mixed>  $llmRuntime
     * @return array<string, mixed>
     */
    private function runDecisionNoteSyncStep(
        $customer,
        string $decisionNote,
        array $decisionTrace,
        string $runtimeHealth,
        array $llmRuntime,
    ): array {
        if (trim($decisionNote) === '') {
            return [
                'status' => 'skipped',
                'reason' => 'no_decision_note',
                'action' => 'decision_note_sync',
            ];
        }

        RetryDecisionNoteToCrmJob::dispatch(
            customerId: $customer->id,
            note: $decisionNote,
        )->onQueue('crm');

        return [
            'status' => 'queued',
            'queue' => 'crm',
            'reason' => 'decision_note_enqueued',
            'action' => 'decision_note_sync',
            'trace_id' => $decisionTrace['trace_id'] ?? null,
            'runtime_health' => $runtimeHealth,
            'llm_runtime' => $this->summarizeRuntimeForWriteback($llmRuntime),
        ];
    }

    /**
     * @param  array<string, mixed>  $intentResult
     * @param  array<string, mixed>  $summaryResult
     * @param  array<string, mixed>  $finalReply
     * @param  array<string, mixed>  $crmContext
     * @param  array<string, mixed>  $llmRuntime
     * @return array<string, mixed>
     */
    private function runContactSyncStep(
        $customer,
        array $intentResult,
        array $summaryResult,
        array $finalReply,
        array $crmContext,
        bool $needsEscalation,
        string $runtimeHealth,
        array $llmRuntime,
    ): array {
        SyncContactToCrmJob::dispatch(
            customerId: $customer->id,
        )->onQueue('crm');

        return [
            'status' => 'queued',
            'queue' => 'crm',
            'reason' => 'contact_sync_enqueued',
            'action' => 'contact_sync',
            'context' => [
                'last_ai_intent' => $intentResult['intent'] ?? null,
                'last_ai_summary' => $summaryResult['summary'] ?? null,
                'customer_interest_topic' => $this->deriveInterestTopic($intentResult, $summaryResult, $finalReply),
                'ai_sentiment' => $summaryResult['sentiment'] ?? null,
                'needs_human_followup' => $needsEscalation,
                'admin_takeover_active' => (bool) ($crmContext['business_flags']['admin_takeover_active'] ?? false),
                'last_whatsapp_interaction_at' => now()->toIso8601String(),
                'ai_runtime_health' => $runtimeHealth,
                'ai_runtime_overall' => $runtimeHealth,
                'ai_runtime_understanding_health' => $this->resolveStageRuntimeHealth(
                    is_array($llmRuntime['understanding'] ?? null) ? $llmRuntime['understanding'] : []
                ),
                'ai_runtime_reply_draft_health' => $this->resolveStageRuntimeHealth(
                    is_array($llmRuntime['reply_draft'] ?? null) ? $llmRuntime['reply_draft'] : []
                ),
                'ai_runtime_grounded_health' => $this->resolveStageRuntimeHealth(
                    is_array($llmRuntime['grounded_response'] ?? null) ? $llmRuntime['grounded_response'] : []
                ),
                'ai_runtime_understanding_model' => $llmRuntime['understanding']['model'] ?? null,
                'ai_runtime_reply_draft_model' => $llmRuntime['reply_draft']['model'] ?? null,
                'ai_runtime_grounded_model' => $llmRuntime['grounded_response']['model'] ?? null,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $crmLeadPipeline
     * @param  array<string, mixed>  $intentResult
     * @param  array<string, mixed>  $summaryResult
     * @param  array<string, mixed>  $finalReply
     * @return array{lead:mixed, report:array<string,mixed>}
     */
    private function runLeadSyncStep(
        $customer,
        Conversation $conversation,
        ?BookingRequest $booking,
        string $intent,
        array $crmLeadPipeline,
        array $intentResult,
        array $summaryResult,
        array $finalReply,
        bool $needsEscalation,
    ): array {
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

        return [
            'lead' => $lead,
            'report' => [
                'status' => $lead === null ? 'skipped' : 'synced',
                'reason' => $lead === null ? 'lead_not_available' : 'lead_synced',
                'action' => 'lead_sync',
                'metadata' => [
                    'lead_id' => $lead?->id,
                    'lead_stage_before' => $crmLeadPipeline['stage'] ?? null,
                    'lead_stage_after' => $lead?->stage,
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $intentResult
     * @param  array<string, mixed>  $crmEscalation
     * @return array<string, mixed>
     */
    private function runEscalationSyncStep(
        Conversation $conversation,
        array $intentResult,
        string $intent,
        bool $needsEscalation,
        array $crmEscalation,
    ): array {
        if (! $needsEscalation) {
            return [
                'status' => 'skipped',
                'reason' => 'no_escalation_needed',
                'action' => 'escalation_sync',
            ];
        }

        if (($crmEscalation['has_open_escalation'] ?? false) === true) {
            return [
                'status' => 'skipped',
                'reason' => 'already_open',
                'action' => 'escalation_sync',
            ];
        }

        $reason = (string) ($intentResult['reasoning_short'] ?? $intent ?? 'AI requested escalation');

        EscalateConversationToAdminJob::dispatch(
            $conversation->id,
            $reason,
            'normal',
        )->onQueue('priority');

        return [
            'status' => 'queued',
            'queue' => 'priority',
            'reason' => 'escalation_enqueued',
            'action' => 'escalation_sync',
            'metadata' => [
                'conversation_id' => $conversation->id,
                'escalation_reason' => $reason,
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
     * @param  array<string, mixed>  $contextSnapshot
     * @param  array<string, mixed>  $intentResult
     * @param  array<string, mixed>  $finalReply
     * @return array<string, mixed>
     */
    private function resolveLlmRuntimeBundle(
        array $contextSnapshot = [],
        array $intentResult = [],
        array $finalReply = [],
    ): array {
        $finalMeta = is_array($finalReply['meta'] ?? null) ? $finalReply['meta'] : [];
        $replyRuntime = is_array($finalMeta['llm_runtime'] ?? null) ? $finalMeta['llm_runtime'] : [];
        $snapshotRuntime = is_array($contextSnapshot['llm_runtime'] ?? null) ? $contextSnapshot['llm_runtime'] : [];
        $understandingRuntime = is_array($contextSnapshot['understanding_runtime'] ?? null)
            ? $contextSnapshot['understanding_runtime']
            : (is_array($intentResult['llm_runtime'] ?? null) ? $intentResult['llm_runtime'] : []);

        return [
            'understanding' => is_array($replyRuntime['understanding'] ?? null)
                ? $replyRuntime['understanding']
                : (is_array($snapshotRuntime['understanding'] ?? null)
                    ? $snapshotRuntime['understanding']
                    : $understandingRuntime),
            'reply_draft' => is_array($replyRuntime['reply_draft'] ?? null)
                ? $replyRuntime['reply_draft']
                : (is_array($snapshotRuntime['reply_draft'] ?? null) ? $snapshotRuntime['reply_draft'] : []),
            'grounded_response' => is_array($replyRuntime['grounded_response'] ?? null)
                ? $replyRuntime['grounded_response']
                : (is_array($snapshotRuntime['grounded_response'] ?? null) ? $snapshotRuntime['grounded_response'] : []),
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
     * @param  array<string, mixed>  $runtime
     */
    private function resolveStageRuntimeHealth(array $runtime): string
    {
        if (($runtime['status'] ?? null) === 'fallback') {
            return 'fallback';
        }

        if (array_key_exists('schema_valid', $runtime) && ($runtime['schema_valid'] ?? true) === false) {
            return 'schema_invalid';
        }

        if (($runtime['degraded_mode'] ?? false) === true) {
            return 'degraded';
        }

        if (($runtime['used_fallback_model'] ?? false) === true) {
            return 'fallback_model';
        }

        if (($runtime['status'] ?? null) === 'success') {
            return 'healthy';
        }

        return 'unknown';
    }

    /**
     * @param  array<string, mixed>  $bundle
     * @return array<string, mixed>
     */
    private function summarizeRuntimeForWriteback(array $bundle): array
    {
        return [
            'overall' => $this->resolveRuntimeHealthFromBundle($bundle),
            'understanding' => [
                'health' => $this->resolveStageRuntimeHealth(
                    is_array($bundle['understanding'] ?? null) ? $bundle['understanding'] : []
                ),
                'model' => $bundle['understanding']['model'] ?? null,
            ],
            'reply_draft' => [
                'health' => $this->resolveStageRuntimeHealth(
                    is_array($bundle['reply_draft'] ?? null) ? $bundle['reply_draft'] : []
                ),
                'model' => $bundle['reply_draft']['model'] ?? null,
            ],
            'grounded_response' => [
                'health' => $this->resolveStageRuntimeHealth(
                    is_array($bundle['grounded_response'] ?? null) ? $bundle['grounded_response'] : []
                ),
                'model' => $bundle['grounded_response']['model'] ?? null,
            ],
        ];
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
