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
use App\Services\Chatbot\ConversationManagerService;
use App\Services\Chatbot\ConversationStateService;
use App\Services\Chatbot\CustomerMemoryService;
use App\Services\Chatbot\ReplyOrchestratorService;
use App\Services\CRM\ContactTaggingService;
use App\Services\CRM\LeadPipelineService;
use App\Services\Knowledge\FaqResolverService;
use App\Services\Knowledge\KnowledgeBaseService;
use App\Services\Support\AuditLogService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessIncomingWhatsAppMessage implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    /** Maximum attempts before the job is marked as permanently failed. */
    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [15, 60, 180];

    public int $timeout = 120;

    public function __construct(
        public readonly int $messageId,
        public readonly int $conversationId,
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
        ReplyOrchestratorService   $replyOrchestrator,
        ContactTaggingService      $contactTagging,
        LeadPipelineService        $leadPipeline,
        AuditLogService            $audit,
        KnowledgeBaseService       $knowledgeBase,
        FaqResolverService         $faqResolver,
    ): void {
        // ── 1. Load models ─────────────────────────────────────────────────
        $message      = ConversationMessage::find($this->messageId);
        $conversation = Conversation::with('customer')->find($this->conversationId);

        if ($message === null || $conversation === null) {
            Log::warning('ProcessIncomingWhatsAppMessage: model not found', [
                'message_id'      => $this->messageId,
                'conversation_id' => $this->conversationId,
            ]);
            return;
        }

        if ($conversation->customer === null) {
            Log::warning('ProcessIncomingWhatsAppMessage: customer not found', [
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
                Log::info('ProcessIncomingWhatsAppMessage: SKIPPED — admin takeover active', [
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
            $intentResult = $intentClassifier->classify($aiContext);
            $aiContext['intent_result'] = $intentResult;

            Log::info('ProcessIncomingWhatsAppMessage: intent classified', [
                'conversation_id' => $conversation->id,
                'intent'          => $intentResult['intent'],
                'confidence'      => $intentResult['confidence'],
            ]);

            // ── 3.5 FAQ resolver (Tahap 10) ─────────────────────────────────
            // Runs after intent is known so caller has full context.
            // FaqResolverService is conservative: only matches when score is very high.
            $faqResult = $faqResolver->resolve($messageText, $knowledgeHits);
            $aiContext['faq_result'] = $faqResult;

            // ── 4. Extract entities ─────────────────────────────────────────
            $entityResult = $entityExtractor->extract($aiContext);
            $aiContext['entity_result'] = $entityResult;

            // ── 5. Generate AI reply ────────────────────────────────────────
            $replyResult = $responseGenerator->generate($aiContext);

            // ── 6. Summarize conversation ───────────────────────────────────
            $summaryResult = $summaryService->summarize($conversation, $aiContext);

            // ── 7. Booking engine (conditional) ────────────────────────────
            [$booking, $bookingDecision] = $this->runBookingEngine(
                conversation     : $conversation,
                message          : $message,
                intentResult     : $intentResult,
                entityResult     : $entityResult,
                bookingAssistant : $bookingAssistant,
            );

            // ── 8. Compose final reply ──────────────────────────────────────
            $orchestratorContext = [
                'conversation'    => $conversation,
                'customer'        => $customer,
                'intentResult'    => $intentResult,
                'entityResult'    => $entityResult,
                'replyResult'     => $replyResult,
                'bookingDecision' => $bookingDecision,
                'booking'         => $booking,
            ];

            $finalReply = $replyOrchestrator->compose($orchestratorContext);

            // ── 9. Persist all results ──────────────────────────────────────
            $outboundMessage = $this->persistResults(
                conversation        : $conversation,
                message             : $message,
                conversationManager : $conversationManager,
                intentResult        : $intentResult,
                summaryResult       : $summaryResult,
                finalReply          : $finalReply,
                bookingDecision     : $bookingDecision,
            );

            // ── 9.5 Dispatch WhatsApp send job for the bot reply ────────────
            SendWhatsAppMessageJob::dispatch($outboundMessage->id);

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

            Log::info('ProcessIncomingWhatsAppMessage: pipeline complete', [
                'conversation_id'  => $conversation->id,
                'message_id'       => $message->id,
                'intent'           => $intentResult['intent'],
                'booking_action'   => $bookingDecision['action'] ?? null,
                'is_fallback'      => $finalReply['is_fallback'],
                'used_knowledge'   => $replyResult['used_knowledge'] ?? false,
                'used_faq'         => $replyResult['used_faq'] ?? false,
                'knowledge_count'  => count($knowledgeHits),
                'outbound_id'      => $outboundMessage->id,
            ]);
        } catch (\Throwable $e) {
            Log::error('ProcessIncomingWhatsAppMessage: pipeline error', [
                'conversation_id' => $this->conversationId,
                'message_id'      => $this->messageId,
                'error'           => $e->getMessage(),
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
            Log::warning('ProcessIncomingWhatsAppMessage: quality label update failed (non-fatal)', [
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
            Log::error('ProcessIncomingWhatsAppMessage: CRM layer failed (non-fatal)', [
                'conversation_id' => $conversation->id,
                'error'           => $e->getMessage(),
            ]);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::critical('ProcessIncomingWhatsAppMessage: permanently failed after retries', [
            'message_id'      => $this->messageId,
            'conversation_id' => $this->conversationId,
            'error'           => $exception->getMessage(),
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

        Log::info('ProcessIncomingWhatsAppMessage: booking engine decision', [
            'conversation_id' => $conversation->id,
            'booking_id'      => $booking->id,
            'action'          => $bookingDecision['action'],
            'booking_status'  => $bookingDecision['booking_status'],
        ]);

        return [$booking, $bookingDecision];
    }

    /**
     * Persist all pipeline results and return the saved outbound message
     * so the caller can dispatch SendWhatsAppMessageJob.
     *
     * @param  array{intent: string, confidence: float, reasoning_short: string}  $intentResult
     * @param  array{summary: string}                                             $summaryResult
     * @param  array{text: string, is_fallback: bool, meta: array}               $finalReply
     * @param  array<string, mixed>|null                                          $bookingDecision
     */
    private function persistResults(
        Conversation $conversation,
        ConversationMessage $message,
        ConversationManagerService $conversationManager,
        array $intentResult,
        array $summaryResult,
        array $finalReply,
        ?array $bookingDecision,
    ): ConversationMessage {
        $message->tagWithAiResult($intentResult['intent'], $intentResult['confidence']);
        $conversation->updateIntent($intentResult['intent']);

        if (! empty($summaryResult['summary'])) {
            $conversation->updateSummary($summaryResult['summary']);
        }

        $rawPayload = array_merge(
            [
                'source'      => $finalReply['meta']['source'] ?? 'ai_generated',
                'is_fallback' => $finalReply['is_fallback'],
                'intent'      => $intentResult['intent'],
            ],
            $bookingDecision !== null ? ['booking_action' => $bookingDecision['action']] : [],
        );

        $outboundMessage = $conversationManager->appendOutboundMessage(
            conversation : $conversation,
            text         : $finalReply['text'],
            rawPayload   : $rawPayload,
        );

        return $outboundMessage;
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
            Log::error('ProcessIncomingWhatsAppMessage: failed to create inbound-during-takeover notification', [
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
            SendWhatsAppMessageJob::dispatch($outbound->id);
        } catch (\Throwable $inner) {
            Log::error('ProcessIncomingWhatsAppMessage: emergency fallback also failed', [
                'error' => $inner->getMessage(),
            ]);
        }
    }
}
