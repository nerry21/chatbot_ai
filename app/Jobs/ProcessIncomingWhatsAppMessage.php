<?php

namespace App\Jobs;

use App\Enums\AuditActionType;
use App\Enums\IntentType;
use App\Models\AdminNotification;
use App\Models\AiLog;
use App\Models\BookingRequest;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Services\AI\ConversationSummaryService;
use App\Services\AI\EntityExtractorService;
use App\Services\AI\IntentClassifierService;
use App\Services\AI\ResponseGeneratorService;
use App\Services\Booking\BookingAssistantService;
use App\Services\Booking\BookingFlowStateMachine;
use App\Services\Chatbot\ConversationManagerService;
use App\Services\Chatbot\ConversationReplyGuardService;
use App\Services\Chatbot\ConversationStateService;
use App\Services\Chatbot\CustomerMemoryService;
use App\Services\Chatbot\ReplyOrchestratorService;
use App\Services\CRM\ContactTaggingService;
use App\Services\CRM\LeadPipelineService;
use App\Services\Knowledge\FaqResolverService;
use App\Services\Knowledge\KnowledgeBaseService;
use App\Services\Support\AuditLogService;
use App\Support\WaLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessIncomingWhatsAppMessage implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    /** Maximum attempts before the job is marked as permanently failed. */
    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [15, 60, 180];

    public int $timeout = 120;

    public function __construct(
        public readonly int    $messageId,
        public readonly int    $conversationId,
        public readonly string $traceId = '',
    ) {}

    public function handle(
        ConversationStateService   $stateService,
        CustomerMemoryService      $memoryService,
        IntentClassifierService    $intentClassifier,
        EntityExtractorService     $entityExtractor,
        ResponseGeneratorService   $responseGenerator,
        ConversationSummaryService $summaryService,
        ConversationManagerService $conversationManager,
        BookingAssistantService    $bookingAssistant,
        BookingFlowStateMachine    $bookingFlow,
        ConversationReplyGuardService $replyGuard,
        ReplyOrchestratorService   $replyOrchestrator,
        ContactTaggingService      $contactTagging,
        LeadPipelineService        $leadPipeline,
        AuditLogService            $audit,
        KnowledgeBaseService       $knowledgeBase,
        FaqResolverService         $faqResolver,
    ): void {
        // ── 0. Restore trace ID from parent request ─────────────────────────
        if ($this->traceId !== '') {
            WaLog::setTrace($this->traceId);
        }

        $jobStartMs = (int) round(microtime(true) * 1000);

        WaLog::info('[Job:ProcessIncoming] Started', [
            'message_id'      => $this->messageId,
            'conversation_id' => $this->conversationId,
            'attempt'         => $this->attempts(),
        ]);

        // ── 1. Load models ─────────────────────────────────────────────────
        $message      = ConversationMessage::find($this->messageId);
        $conversation = Conversation::with('customer')->find($this->conversationId);

        if ($message === null || $conversation === null) {
            WaLog::warning('[Job:ProcessIncoming] Model not found — aborting', [
                'message_id'      => $this->messageId,
                'conversation_id' => $this->conversationId,
            ]);
            return;
        }

        if ($conversation->customer === null) {
            WaLog::warning('[Job:ProcessIncoming] Customer not found — aborting', [
                'conversation_id' => $this->conversationId,
            ]);
            return;
        }

        $customer = $conversation->customer;

        try {
            // ── 1.5 Guard: admin takeover — bot pipeline suppressed ─────────
            // This guard MUST run before ANY AI pipeline step.
            // When handoff_mode = 'admin', the conversation is owned by a human;
            // the bot must not generate or dispatch any auto-reply.
            if ($conversation->isAdminTakeover()) {
                WaLog::info('[Job:ProcessIncoming] SKIPPED — admin takeover active', [
                    'conversation_id'  => $conversation->id,
                    'handoff_admin_id' => $conversation->handoff_admin_id,
                    'handoff_at'       => $conversation->handoff_at?->toDateTimeString(),
                ]);

                // Audit: record the suppressed auto-reply
                $audit->record(AuditActionType::BotReplySkippedTakeover, [
                    'actor_user_id'   => null, // System action
                    'conversation_id' => $conversation->id,
                    'message'         => 'Auto-reply bot diblokir karena admin takeover aktif.',
                    'context'         => [
                        'conversation_id'  => $conversation->id,
                        'message_id'       => $message->id,
                        'handoff_admin_id' => $conversation->handoff_admin_id,
                        'text_preview'     => $message->textPreview(80),
                    ],
                ]);

                // Operational notification — alert admin that a message arrived during takeover
                if (
                    config('chatbot.notifications.enabled', true)
                    && config('chatbot.notifications.create_on_inbound_during_takeover', true)
                ) {
                    $this->notifyInboundDuringTakeover($message, $conversation, $customer);
                }

                return; // Inbound message is stored; nothing else runs.
            }

            // ── 2. Build base AI context ────────────────────────────────────
            $activeStates   = $stateService->allActive($conversation);
            $customerMemory = $memoryService->buildMemory($customer);
            $recentMessages = $this->loadRecentMessages($conversation, $this->messageId);
            $messageText    = $message->message_text ?? '';

            $aiContext = [
                'conversation_id' => $conversation->id,
                'message_id'      => $message->id,
                'message_text'    => $messageText,
                'customer_memory' => $customerMemory,
                'active_states'   => $activeStates,
                'recent_messages' => $recentMessages,
            ];

            // ── 2.5 Knowledge retrieval (Tahap 10) ──────────────────────────
            // Fetch once, reuse across all AI steps.
            // Intentionally lightweight: PHP-level scoring, no external services.
            [$knowledgeHits, $knowledgeBlock, $knowledgeHint] = $this->fetchKnowledge(
                knowledgeBase : $knowledgeBase,
                messageText   : $messageText,
            );

            $aiContext['knowledge_hits']  = $knowledgeHits ?: null; // null = no hits (cleaner for logging)
            $aiContext['knowledge_block'] = $knowledgeBlock;
            $aiContext['knowledge_hint']  = $knowledgeHint;

            // ── 3. Classify intent ──────────────────────────────────────────
            $stepStart = (int) round(microtime(true) * 1000);
            WaLog::debug('[Job:ProcessIncoming] AI:intent START', [
                'conversation_id' => $conversation->id,
                'message_preview' => mb_substr($messageText, 0, 60),
                'knowledge_hits'  => count($knowledgeHits),
            ]);
            $intentResult = $intentClassifier->classify($aiContext);
            $aiContext['intent_result'] = $intentResult;
            WaLog::info('[Job:ProcessIncoming] AI:intent END', [
                'conversation_id' => $conversation->id,
                'intent'          => $intentResult['intent'],
                'confidence'      => $intentResult['confidence'],
                'duration_ms'     => (int) round(microtime(true) * 1000) - $stepStart,
            ]);

            // ── 3.5 FAQ resolver (Tahap 10) ─────────────────────────────────
            // Runs after intent is known so caller has full context.
            // FaqResolverService is conservative: only matches when score is very high.
            $faqResult = $faqResolver->resolve($messageText, $knowledgeHits);
            $aiContext['faq_result'] = $faqResult;
            if ($faqResult['matched'] ?? false) {
                WaLog::info('[Job:ProcessIncoming] FAQ matched — LLM reply may be skipped', [
                    'conversation_id' => $conversation->id,
                    'faq_id'          => $faqResult['id'] ?? null,
                    'score'           => $faqResult['score'] ?? null,
                ]);
            }

            // ── 4. Extract entities ─────────────────────────────────────────
            $stepStart = (int) round(microtime(true) * 1000);
            WaLog::debug('[Job:ProcessIncoming] AI:extraction START', [
                'conversation_id' => $conversation->id,
            ]);
            $entityResult = $entityExtractor->extract($aiContext);
            $aiContext['entity_result'] = $entityResult;
            WaLog::debug('[Job:ProcessIncoming] AI:extraction END', [
                'conversation_id' => $conversation->id,
                'entity_keys'     => array_keys($entityResult),
                'duration_ms'     => (int) round(microtime(true) * 1000) - $stepStart,
            ]);

            // ── 5. Generate AI reply ────────────────────────────────────────
            $stepStart = (int) round(microtime(true) * 1000);
            WaLog::debug('[Job:ProcessIncoming] AI:reply START', [
                'conversation_id' => $conversation->id,
            ]);
            $replyResult = $responseGenerator->generate($aiContext);
            WaLog::info('[Job:ProcessIncoming] AI:reply END', [
                'conversation_id' => $conversation->id,
                'is_fallback'     => $replyResult['is_fallback'] ?? false,
                'source'          => $replyResult['meta']['source'] ?? null,
                'used_faq'        => $replyResult['used_faq'] ?? false,
                'used_knowledge'  => $replyResult['used_knowledge'] ?? false,
                'duration_ms'     => (int) round(microtime(true) * 1000) - $stepStart,
            ]);

            // ── 6. Summarize conversation ───────────────────────────────────
            $stepStart     = (int) round(microtime(true) * 1000);
            $summaryResult = $summaryService->summarize($conversation, $aiContext);
            WaLog::debug('[Job:ProcessIncoming] AI:summary END', [
                'conversation_id' => $conversation->id,
                'has_summary'     => ! empty($summaryResult['summary']),
                'duration_ms'     => (int) round(microtime(true) * 1000) - $stepStart,
            ]);

            // ── 7. Deterministic JET booking flow ──────────────────────
            $flowDecision = $bookingFlow->handle(
                conversation : $conversation,
                customer     : $customer,
                message      : $message,
                intentResult : $intentResult,
                entityResult : $entityResult,
                replyResult  : $replyResult,
            );

            $booking = $flowDecision['booking'] ?? null;
            $bookingDecision = $flowDecision['booking_decision'] ?? null;
            $finalReply = $flowDecision['reply'];
            $intentResult = $flowDecision['intent_result'] ?? $intentResult;
            $guardResult = $replyGuard->guardReply(
                conversation : $conversation,
                messageText  : $messageText,
                entityResult : $entityResult,
                reply        : $finalReply,
            );
            $finalReply = $guardResult['reply'];

            if ($guardResult['close_intent_detected']) {
                $intentResult = array_merge($intentResult, [
                    'intent'          => IntentType::Farewell->value,
                    'confidence'      => max((float) ($intentResult['confidence'] ?? 0), 0.99),
                    'reasoning_short' => 'Close intent detected after unavailable route.',
                ]);

                WaLog::info('[Job:ProcessIncoming] Close intent detected — conversation will be closed politely', [
                    'conversation_id' => $conversation->id,
                    'message_id'      => $message->id,
                    'text_preview'    => $message->textPreview(80),
                ]);
            }

            if ($guardResult['unavailable_repeat_blocked']) {
                WaLog::info('[Job:ProcessIncoming] Unavailable route no-repeat guard applied', [
                    'conversation_id' => $conversation->id,
                    'message_id'      => $message->id,
                    'booking_action'  => $bookingDecision['action'] ?? null,
                    'text_preview'    => $message->textPreview(80),
                ]);
            }

            // ── 9. Persist all results ──────────────────────────────────────
            $outboundMessage = $this->persistResults(
                conversation        : $conversation,
                message             : $message,
                conversationManager : $conversationManager,
                replyGuard          : $replyGuard,
                intentResult        : $intentResult,
                summaryResult       : $summaryResult,
                finalReply          : $finalReply,
                booking             : $booking,
                guardResult         : $guardResult,
                bookingDecision     : $bookingDecision,
            );

            // ── 9.5 Dispatch WhatsApp send job for the bot reply ────────────
            if ($outboundMessage !== null) {
                SendWhatsAppMessageJob::dispatch($outboundMessage->id, WaLog::traceId());
            }

            if (($guardResult['close_conversation'] ?? false) || (($finalReply['meta']['close_conversation'] ?? false) === true)) {
                $conversationManager->close($conversation);

                WaLog::info('[Job:ProcessIncoming] Conversation closed after close intent', [
                    'conversation_id' => $conversation->id,
                    'message_id'      => $message->id,
                ]);
            }

            // ── 9.7 Update AI quality labels (Tahap 10) ─────────────────────
            // Non-fatal: must not break the pipeline on failure.
            if (config('chatbot.ai_quality.enabled', true)) {
                $this->updateQualityLabels(
                    conversationId : $conversation->id,
                    messageId      : $message->id,
                    intentResult   : $intentResult,
                    replyResult    : $replyResult,
                    faqResult      : $faqResult,
                    knowledgeHits  : $knowledgeHits,
                );
            }

            // ── 10. CRM layer (graceful — must not fail the pipeline) ───────
            $this->runCrmOperations(
                conversation   : $conversation,
                booking        : $booking,
                intentResult   : $intentResult,
                summaryResult  : $summaryResult,
                contactTagging : $contactTagging,
                leadPipeline   : $leadPipeline,
            );

            $durationMs = (int) round(microtime(true) * 1000) - $jobStartMs;

            WaLog::info('[Job:ProcessIncoming] Pipeline complete', [
                'conversation_id' => $conversation->id,
                'message_id'      => $message->id,
                'intent'          => $intentResult['intent'],
                'confidence'      => $intentResult['confidence'],
                'booking_action'  => $bookingDecision['action'] ?? null,
                'is_fallback'     => $finalReply['is_fallback'],
                'used_knowledge'  => $replyResult['used_knowledge'] ?? false,
                'used_faq'        => $replyResult['used_faq'] ?? false,
                'knowledge_count' => count($knowledgeHits),
                'outbound_id'     => $outboundMessage?->id,
                'outbound_skipped' => $outboundMessage === null,
                'duration_ms'     => $durationMs,
            ]);
        } catch (\Throwable $e) {
            $durationMs = (int) round(microtime(true) * 1000) - $jobStartMs;

            WaLog::error('[Job:ProcessIncoming] Pipeline error — emergency fallback triggered', [
                'conversation_id' => $this->conversationId,
                'message_id'      => $this->messageId,
                'error'           => $e->getMessage(),
                'file'            => $e->getFile() . ':' . $e->getLine(),
                'duration_ms'     => $durationMs,
                'trace'           => $e->getTraceAsString(),
            ]);

            $this->saveEmergencyFallback($conversation, $conversationManager);

            throw $e;
        }
    }

    // -------------------------------------------------------------------------
    // Tahap 10: Knowledge helpers
    // -------------------------------------------------------------------------

    /**
     * Fetch knowledge articles relevant to the current message.
     * Returns three parallel values: hits array, full context block, compact hint.
     * Safe: returns empty values if knowledge is disabled or search fails.
     *
     * @return array{0: array<int, array<string, mixed>>, 1: string, 2: string}
     */
    private function fetchKnowledge(KnowledgeBaseService $knowledgeBase, string $messageText): array
    {
        if (! config('chatbot.knowledge.enabled', true) || trim($messageText) === '') {
            return [[], '', ''];
        }

        $hits = $knowledgeBase->search($messageText);

        if (empty($hits)) {
            return [[], '', ''];
        }

        return [
            $hits,
            $knowledgeBase->buildContextBlock($hits),
            $knowledgeBase->buildCompactHint($hits),
        ];
    }

    /**
     * Update quality labels on ai_log rows after the pipeline completes.
     *
     * Strategy:
     *  - Intent log: label 'low_confidence' when confidence ≤ threshold.
     *  - Reply log:
     *      - 'faq_direct'      → FAQ local answer was used (no LLM call for reply).
     *      - 'knowledge_used'  → LLM was called and knowledge context was injected.
     *      - 'fallback'        → LLM was not called or returned empty; used hardcoded fallback.
     *
     * Uses `latest()` so multiple retries of the same message don't corrupt earlier logs.
     *
     * @param  array<int, array<string, mixed>>  $knowledgeHits
     * @param  array<string, mixed>              $intentResult
     * @param  array<string, mixed>              $replyResult
     * @param  array<string, mixed>              $faqResult
     */
    private function updateQualityLabels(
        int $conversationId,
        int $messageId,
        array $intentResult,
        array $replyResult,
        array $faqResult,
        array $knowledgeHits,
    ): void {
        try {
            $threshold = (float) config('chatbot.ai_quality.low_confidence_threshold', 0.40);

            // ── Intent quality label ────────────────────────────────────────
            if (($intentResult['confidence'] ?? 1.0) <= $threshold) {
                AiLog::where('conversation_id', $conversationId)
                    ->where('message_id', $messageId)
                    ->where('task_type', 'intent_classification')
                    ->latest()
                    ->limit(1)
                    ->update(['quality_label' => 'low_confidence']);
            }

            // ── Reply quality label ─────────────────────────────────────────
            if ($faqResult['matched'] ?? false) {
                // FAQ direct: no LLM call was made — create a log row to record this event
                if (config('chatbot.ai_quality.store_knowledge_hits', true)) {
                    AiLog::writeLog('reply_generation', 'success', [
                        'conversation_id' => $conversationId,
                        'message_id'      => $messageId,
                        'quality_label'   => 'faq_direct',
                        'knowledge_hits'  => ! empty($knowledgeHits) ? $knowledgeHits : null,
                    ]);
                }
            } else {
                // LLM was called — update the existing log row
                $replyLabel = null;

                if ($replyResult['used_knowledge'] ?? false) {
                    $replyLabel = 'knowledge_used';
                } elseif ($replyResult['is_fallback'] ?? false) {
                    $replyLabel = 'fallback';
                }

                if ($replyLabel !== null) {
                    AiLog::where('conversation_id', $conversationId)
                        ->where('message_id', $messageId)
                        ->where('task_type', 'reply_generation')
                        ->latest()
                        ->limit(1)
                        ->update(['quality_label' => $replyLabel]);
                }
            }
        } catch (\Throwable $e) {
            WaLog::warning('[Job:ProcessIncoming] quality label update failed (non-fatal)', [
                'conversation_id' => $conversationId,
                'message_id'      => $messageId,
                'error'           => $e->getMessage(),
            ]);
        }
    }

    // -------------------------------------------------------------------------
    // CRM layer
    // -------------------------------------------------------------------------

    private function runCrmOperations(
        Conversation $conversation,
        ?BookingRequest $booking,
        array $intentResult,
        array $summaryResult,
        ContactTaggingService $contactTagging,
        LeadPipelineService $leadPipeline,
    ): void {
        try {
            $customer   = $conversation->customer;
            $intentStr  = $intentResult['intent'] ?? null;
            $intentEnum = $intentStr !== null ? IntentType::tryFrom($intentStr) : null;

            // a. Apply CRM tags
            $contactTagging->applyBasicTags($customer, $booking, $intentStr);

            // b. Sync / advance lead pipeline
            $leadPipeline->syncFromContext($customer, $conversation, $booking, $intentStr);

            // c. Escalation job if human intervention is needed
            $needsEscalation = $conversation->needs_human
                || ($intentEnum !== null && $intentEnum->requiresHuman());

            if ($needsEscalation) {
                \App\Jobs\EscalateConversationToAdminJob::dispatch(
                    $conversation->id,
                    $intentResult['reasoning_short'] ?? $intentStr ?? '',
                    'normal',
                );
            }

            // d. Async contact sync
            \App\Jobs\SyncContactToCrmJob::dispatch($customer->id);

            // e. Async summary sync
            if (! empty($summaryResult['summary'])) {
                \App\Jobs\SyncConversationSummaryToCrmJob::dispatch(
                    $customer->id,
                    $conversation->id,
                );
            }
        } catch (\Throwable $e) {
            WaLog::error('[Job:ProcessIncoming] CRM layer failed (non-fatal)', [
                'conversation_id' => $conversation->id,
                'error'           => $e->getMessage(),
                'file'            => $e->getFile() . ':' . $e->getLine(),
            ]);
        }
    }

    public function failed(\Throwable $exception): void
    {
        WaLog::critical('[Job:ProcessIncoming] permanently failed after retries', [
            'message_id'      => $this->messageId,
            'conversation_id' => $this->conversationId,
            'error'           => $exception->getMessage(),
            'file'            => $exception->getFile() . ':' . $exception->getLine(),
            'trace'           => $exception->getTraceAsString(),
        ]);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * @param  array{intent: string, confidence: float, reasoning_short: string}  $intentResult
     * @param  array<string, mixed>  $entityResult
     * @return array{0: BookingRequest|null, 1: array<string,mixed>|null}
     */
    private function runBookingEngine(
        Conversation $conversation,
        ConversationMessage $message,
        array $intentResult,
        array $entityResult,
        BookingAssistantService $bookingAssistant,
    ): array {
        $intentEnum       = IntentType::tryFrom($intentResult['intent']);
        $isBookingRelated = $intentEnum !== null && $intentEnum->isBookingRelated();
        $existingDraft    = $bookingAssistant->findExistingDraft($conversation);

        if (! $isBookingRelated && $existingDraft === null) {
            return [null, null];
        }

        $booking = $existingDraft ?? $bookingAssistant->findOrCreateDraft($conversation);

        $rawAiPayload = [
            'intent_result' => $intentResult,
            'entity_result' => $entityResult,
        ];

        $booking = $bookingAssistant->applyExtraction(
            booking      : $booking,
            entities     : $entityResult,
            message      : $message,
            rawAiPayload : $rawAiPayload,
        );

        $bookingDecision = $bookingAssistant->decideNextStep($booking, $intentResult['intent']);

        WaLog::info('[Job:ProcessIncoming] booking engine decision', [
            'conversation_id' => $conversation->id,
            'booking_id'      => $booking->id,
            'action'          => $bookingDecision['action'],
            'booking_status'  => $bookingDecision['booking_status'],
        ]);

        return [$booking, $bookingDecision];
    }

    /**
     * Persist message/conversation AI results that do not depend on whether an
     * outbound reply is eventually sent.
     *
     * @param  array{intent: string, confidence: float, reasoning_short: string}  $intentResult
     * @param  array{summary: string}                                             $summaryResult
     */
    private function persistConversationResults(
        Conversation $conversation,
        ConversationMessage $message,
        array $intentResult,
        array $summaryResult,
    ): void {
        $message->tagWithAiResult($intentResult['intent'], $intentResult['confidence']);
        $conversation->updateIntent($intentResult['intent']);

        if (! empty($summaryResult['summary'])) {
            $conversation->updateSummary($summaryResult['summary']);
        }
    }

    /**
     * Persist the outbound reply unless anti-repeat requires it to be skipped.
     *
     * @param  array{intent: string, confidence: float, reasoning_short: string}  $intentResult
     * @param  array{text: string, is_fallback: bool, meta: array<string, mixed>}  $finalReply
     * @param  array<string, mixed>                                                $guardResult
     * @param  array<string, mixed>|null                                           $bookingDecision
     */
    private function persistResults(
        Conversation $conversation,
        ConversationMessage $message,
        ConversationManagerService $conversationManager,
        ConversationReplyGuardService $replyGuard,
        array $intentResult,
        array $summaryResult,
        array $finalReply,
        ?BookingRequest $booking,
        array $guardResult,
        ?array $bookingDecision,
    ): ?ConversationMessage {
        $this->persistConversationResults(
            conversation  : $conversation,
            message       : $message,
            intentResult  : $intentResult,
            summaryResult : $summaryResult,
        );

        $latestOutbound = $conversation->latestOutboundMessage();

        if ($replyGuard->shouldSkipRepeat($latestOutbound, $finalReply['text'])) {
            WaLog::info('[Job:ProcessIncoming] Anti-repeat skip — outbound identical to latest reply', [
                'conversation_id'    => $conversation->id,
                'message_id'         => $message->id,
                'latest_outbound_id' => $latestOutbound?->id,
                'latest_preview'     => $latestOutbound?->textPreview(80),
                'candidate_preview'  => mb_substr((string) $finalReply['text'], 0, 80),
                'reply_source'       => $finalReply['meta']['source'] ?? null,
                'reply_action'       => $finalReply['meta']['action'] ?? null,
            ]);

            $this->syncUnavailableContext(
                conversation : $conversation,
                booking      : $booking,
                finalReply   : $finalReply,
                guardResult  : $guardResult,
                replyGuard   : $replyGuard,
                outboundSent : false,
            );

            return null;
        }

        $outboundMessage = $this->persistOutboundReply(
            conversation        : $conversation,
            conversationManager : $conversationManager,
            intentResult        : $intentResult,
            finalReply          : $finalReply,
            bookingDecision     : $bookingDecision,
        );

        $this->syncUnavailableContext(
            conversation : $conversation,
            booking      : $booking,
            finalReply   : $finalReply,
            guardResult  : $guardResult,
            replyGuard   : $replyGuard,
            outboundSent : true,
        );

        return $outboundMessage;
    }

    /**
     * Persist an outbound bot reply row.
     *
     * @param  array{intent: string, confidence: float, reasoning_short: string}  $intentResult
     * @param  array{text: string, is_fallback: bool, meta: array<string, mixed>}  $finalReply
     * @param  array<string, mixed>|null                                           $bookingDecision
     */
    private function persistOutboundReply(
        Conversation $conversation,
        ConversationManagerService $conversationManager,
        array $intentResult,
        array $finalReply,
        ?array $bookingDecision,
    ): ConversationMessage {
        $rawPayload = array_filter([
            'source'        => $finalReply['meta']['source'] ?? 'ai_generated',
            'reply_action'  => $finalReply['meta']['action'] ?? null,
            'is_fallback'   => $finalReply['is_fallback'],
            'intent'        => $intentResult['intent'],
            'booking_action' => $bookingDecision['action'] ?? null,
            'outbound_payload' => is_array($finalReply['outbound_payload'] ?? null)
                ? $finalReply['outbound_payload']
                : null,
        ], static fn (mixed $value): bool => $value !== null);

        return $conversationManager->appendOutboundMessage(
            conversation : $conversation,
            text         : $finalReply['text'],
            messageType  : $finalReply['message_type'] ?? 'text',
            rawPayload   : $rawPayload,
        );
    }

    /**
     * @param  array<string, mixed>  $guardResult
     * @param  array{text: string, is_fallback: bool, meta: array<string, mixed>}  $finalReply
     */
    private function syncUnavailableContext(
        Conversation $conversation,
        ?BookingRequest $booking,
        array $finalReply,
        array $guardResult,
        ConversationReplyGuardService $replyGuard,
        bool $outboundSent,
    ): void {
        if ($guardResult['close_conversation'] ?? false) {
            $replyGuard->clearUnavailableContext($conversation);
            return;
        }

        if ($replyGuard->isUnavailableReply($finalReply)) {
            if ($outboundSent) {
                $replyGuard->rememberUnavailableContext($conversation, $booking, $finalReply);
            }

            return;
        }

        if (($finalReply['meta']['source'] ?? null) === 'guard.unavailable_followup') {
            return;
        }

        if ($guardResult['has_unavailable_context'] ?? false) {
            $replyGuard->clearUnavailableContext($conversation);
        }
    }

    /**
     * @return array<int, array{direction: string, text: string|null, sent_at: string|null}>
     */
    private function loadRecentMessages(Conversation $conversation, int $excludeMessageId): array
    {
        $limit = (int) config('chatbot.memory.max_recent_messages', 10);

        return $conversation->messages()
            ->where('id', '!=', $excludeMessageId)
            ->orderByDesc('sent_at')
            ->limit($limit)
            ->get(['direction', 'message_text', 'sent_at'])
            ->reverse()
            ->map(fn (ConversationMessage $m) => [
                'direction' => $m->direction->value,
                'text'      => $m->message_text,
                'sent_at'   => $m->sent_at?->toDateTimeString(),
            ])
            ->values()
            ->all();
    }

    private function notifyInboundDuringTakeover(
        ConversationMessage $message,
        Conversation $conversation,
        \App\Models\Customer $customer,
    ): void {
        try {
            $customerName = $customer->name ?? $customer->phone_e164 ?? 'Pelanggan';

            AdminNotification::create([
                'type'    => 'inbound_during_takeover',
                'title'   => "Pesan baru saat takeover: {$customerName}",
                'body'    => implode("\n", [
                    'Pesan masuk saat admin takeover aktif (bot tidak membalas).',
                    'Percakapan : #' . $conversation->id,
                    'Customer   : ' . $customerName . ' (' . ($customer->phone_e164 ?? '-') . ')',
                    'Pesan      : ' . $message->textPreview(200),
                ]),
                'payload' => [
                    'conversation_id'  => $conversation->id,
                    'message_id'       => $message->id,
                    'handoff_admin_id' => $conversation->handoff_admin_id,
                    'customer_id'      => $customer->id,
                ],
                'is_read' => false,
            ]);
        } catch (\Throwable $e) {
            WaLog::error('[Job:ProcessIncoming] failed to create inbound-during-takeover notification', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function saveEmergencyFallback(
        Conversation $conversation,
        ConversationManagerService $conversationManager,
    ): void {
        try {
            $outbound = $conversationManager->appendOutboundMessage(
                conversation : $conversation,
                text         : 'Maaf, sistem kami sedang mengalami gangguan sementara. Tim kami akan segera merespons pesan Anda.',
                rawPayload   : ['source' => 'emergency_fallback'],
            );

            // Still attempt to send the fallback message to the customer.
            SendWhatsAppMessageJob::dispatch($outbound->id, WaLog::traceId());
        } catch (\Throwable $inner) {
            WaLog::error('[Job:ProcessIncoming] emergency fallback also failed', [
                'error' => $inner->getMessage(),
                'file'  => $inner->getFile() . ':' . $inner->getLine(),
            ]);
        }
    }
}



