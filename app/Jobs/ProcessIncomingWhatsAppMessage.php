<?php

namespace App\Jobs;

use App\Data\AI\LearningSignalPayload;
use App\Enums\AuditActionType;
use App\Enums\IntentType;
use App\Models\AdminNotification;
use App\Models\AiLog;
use App\Models\BookingRequest;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Customer;
use App\Services\AI\ConversationSummaryService;
use App\Services\AI\EntityExtractorService;
use App\Services\AI\GroundedResponseComposerService;
use App\Services\AI\GroundedResponseFactsBuilderService;
use App\Services\AI\IntentClassifierService;
use App\Services\AI\Learning\LearningSignalLoggerService;
use App\Services\AI\LlmUnderstandingEngine;
use App\Services\AI\ResponseGeneratorService;
use App\Services\AI\UnderstandingResultAdapterService;
use App\Services\Booking\BookingAssistantService;
use App\Services\Booking\BookingFlowStateMachine;
use App\Services\Chatbot\BotAutomationToggleService;
use App\Services\Chatbot\ConversationContextLoaderService;
use App\Services\Chatbot\ConversationManagerService;
use App\Services\Chatbot\ConversationOutboundRouterService;
use App\Services\Chatbot\ConversationReplyGuardService;
use App\Services\Chatbot\Guardrails\AdminTakeoverGuardService;
use App\Services\Chatbot\Guardrails\PolicyGuardService;
use App\Services\Chatbot\ReplyOrchestratorService;
use App\Services\CRM\CrmOrchestrationSnapshotService;
use App\Services\CRM\CRMWritebackService;
use App\Services\Knowledge\FaqResolverService;
use App\Services\Knowledge\KnowledgeBaseService;
use App\Services\Support\AuditLogService;
use App\Support\WaLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

class ProcessIncomingWhatsAppMessage implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    /** Maximum attempts before the job is marked as permanently failed. */
    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [15, 60, 180];

    public int $timeout = 120;

    public function __construct(
        public readonly int $messageId,
        public readonly int $conversationId,
        public readonly string $traceId = '',
    ) {}

    public function handle(
        ConversationContextLoaderService $contextLoader,
        AdminTakeoverGuardService $adminTakeoverGuard,
        BotAutomationToggleService $botToggleService,
        PolicyGuardService $policyGuard,
        GroundedResponseFactsBuilderService $groundedFactsBuilder,
        GroundedResponseComposerService $groundedComposer,
        LlmUnderstandingEngine $understandingEngine,
        UnderstandingResultAdapterService $understandingAdapter,
        IntentClassifierService $intentClassifier,
        EntityExtractorService $entityExtractor,
        ResponseGeneratorService $responseGenerator,
        ConversationSummaryService $summaryService,
        LearningSignalLoggerService $learningSignalLogger,
        ConversationManagerService $conversationManager,
        BookingAssistantService $bookingAssistant,
        BookingFlowStateMachine $bookingFlow,
        ConversationReplyGuardService $replyGuard,
        ReplyOrchestratorService $replyOrchestrator,
        ConversationOutboundRouterService $outboundRouter,
        CRMWritebackService $crmWriteback,
        CrmOrchestrationSnapshotService $crmSnapshotService,
        AuditLogService $audit,
        KnowledgeBaseService $knowledgeBase,
        FaqResolverService $faqResolver,
    ): void {
        // ── 0. Restore trace ID from parent request ─────────────────────────
        if ($this->traceId !== '') {
            WaLog::setTrace($this->traceId);
        }

        $jobStartMs = (int) round(microtime(true) * 1000);

        WaLog::info('[Job:ProcessIncoming] Started', [
            'message_id' => $this->messageId,
            'conversation_id' => $this->conversationId,
            'attempt' => $this->attempts(),
        ]);

        // ── 1. Load models ─────────────────────────────────────────────────
        $message = ConversationMessage::find($this->messageId);
        $conversation = Conversation::with('customer')->find($this->conversationId);

        if ($message === null || $conversation === null) {
            WaLog::warning('[Job:ProcessIncoming] Model not found — aborting', [
                'message_id' => $this->messageId,
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

        $contextPayload = null;

        $aiContext = [
            'job_trace_id' => WaLog::traceId(),
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
            'pipeline_stage' => 'boot',
        ];

        $knowledgeHits = [];
        $policyGuardResult = [
            'intent_result' => [],
            'entity_result' => [],
            'meta' => [],
        ];
        $hallucinationGuardResult = [
            'meta' => [],
        ];
        $guardResult = [];
        $ruleEvaluation = [
            'rule_hits' => [],
            'actions' => [],
        ];
        $replyAuditSnapshot = [];
        $orchestrationSnapshot = [];
        $crmSnapshot = [];
        $summaryResult = ['summary' => ''];

        $replyResult = [
            'text' => '',
            'is_fallback' => false,
            'used_knowledge' => false,
            'used_faq' => false,
            'meta' => [],
        ];

        $finalReply = [
            'text' => '',
            'is_fallback' => false,
            'meta' => [],
        ];

        $intentResult = [
            'intent' => IntentType::Unknown->value,
            'confidence' => 0.0,
            'reasoning_short' => 'Learning signal default state.',
            'runtime_health' => 'unknown',
        ];

        $entityResult = [];
        $understandingRuntimeMeta = [];
        $booking = null;
        $bookingDecision = null;
        $outboundMessage = null;
        $conversationLock = Cache::lock('chatbot:conversation:'.$conversation->id, 30);

        if (! $conversationLock->get()) {
            WaLog::warning('[Job:ProcessIncoming] Conversation lock busy - releasing job', [
                'conversation_id' => $conversation->id,
                'message_id' => $message->id,
            ]);

            $this->release(5);

            return;
        }

        try {
            $conversation = $botToggleService->resumeIfDue($conversation);
            // ── 1.5 Guard: admin takeover — bot pipeline suppressed ─────────
            // This guard MUST run before ANY AI pipeline step.
            // When handoff_mode = 'admin', the conversation is owned by a human;
            // the bot must not generate or dispatch any auto-reply.
            if ($adminTakeoverGuard->shouldSuppressAutomation($conversation)) {
                $takeoverContext = $adminTakeoverGuard->context($conversation);
                WaLog::info('[Job:ProcessIncoming] SKIPPED — admin takeover active', [
                    'conversation_id' => $conversation->id,
                    'handoff_admin_id' => $takeoverContext['handoff_admin_id'],
                    'handoff_at' => $takeoverContext['handoff_at'],
                ]);

                // Audit: record the suppressed auto-reply
                $audit->record(AuditActionType::BotReplySkippedTakeover, [
                    'actor_user_id' => null, // System action
                    'conversation_id' => $conversation->id,
                    'message' => 'Auto-reply bot diblokir karena admin takeover aktif.',
                    'context' => [
                        'conversation_id' => $conversation->id,
                        'message_id' => $message->id,
                        'handoff_admin_id' => $takeoverContext['handoff_admin_id'],
                        'text_preview' => $message->textPreview(80),
                    ],
                ]);

                // Operational notification — alert admin that a message arrived during takeover
                if (
                    config('chatbot.notifications.enabled', true)
                    && config('chatbot.notifications.create_on_inbound_during_takeover', true)
                ) {
                    $this->notifyInboundDuringTakeover($message, $conversation, $customer);
                }

                $this->logLearningTurnSafely(
                    learningSignalLogger: $learningSignalLogger,
                    payload: new LearningSignalPayload(
                        conversationId: $conversation->id,
                        inboundMessageId: $message->id,
                        userMessage: (string) ($message->message_text ?? ''),
                        contextSummary: $conversation->summary,
                        contextSnapshot: [
                            'admin_takeover' => true,
                            'handoff_mode' => $conversation->handoff_mode,
                            'handoff_admin_id' => $takeoverContext['handoff_admin_id'] ?? null,
                            'handoff_at' => $takeoverContext['handoff_at'] ?? null,
                        ],
                        understandingResult: [],
                        chosenAction: 'admin_takeover_suppressed',
                        groundedFacts: null,
                        finalResponse: '',
                        finalResponseMeta: ['source' => 'admin_takeover_guard'],
                        fallbackUsed: false,
                        handoffHappened: true,
                        adminTakeoverActive: true,
                        outboundSent: false,
                        outboundMessageId: null,
                        classifierContext: [
                            'policy_guard' => [
                                'action' => 'blocked_takeover',
                                'reasons' => ['admin_takeover_active'],
                            ],
                        ],
                    ),
                );

                return; // Inbound message is stored; nothing else runs.
            }

            // ── 2. Build base AI context ────────────────────────────────────
            $aiContext['pipeline_stage'] = 'context_loading';

            $contextPayload = $contextLoader->load($conversation, $message);
            $messageText = $contextPayload->latestMessageText;

            $crmSnapshot = $crmSnapshotService->build(
                customer: $customer,
                conversation: $conversation,
                booking: null,
                contextPayload: $contextPayload->toArray(),
                intentResult: [],
                entityResult: [],
                bookingDecision: null,
            );

            $contextPayload = $contextPayload->withCrmContext($crmSnapshot);

            $aiContext = array_merge($contextPayload->toAiContext(), [
                'job_trace_id' => WaLog::traceId(),
                'conversation_id' => $conversation->id,
                'message_id' => $message->id,
                'pipeline_stage' => 'context_loaded',
                'crm_context' => $crmSnapshot,
                'understanding_mode' => 'llm_first_with_crm_hints_only',
            ]);

            // ── 2.5 Knowledge retrieval (Tahap 10) ──────────────────────────
            // Fetch once, reuse across all AI steps.
            // Intentionally lightweight: PHP-level scoring, no external services.
            [$knowledgeHits, $knowledgeBlock, $knowledgeHint] = $this->fetchKnowledge(
                knowledgeBase : $knowledgeBase,
                messageText   : $messageText,
            );

            $aiContext['knowledge_hits'] = $knowledgeHits ?: null; // null = no hits (cleaner for logging)
            $aiContext['knowledge_block'] = $knowledgeBlock;
            $aiContext['knowledge_hint'] = $knowledgeHint;

            // ── 3. LLM-first understanding ─────────────────────────────────
            $aiContext['pipeline_stage'] = 'understanding';

            [$understandingResult, $understandingRuntimeMeta] = $this->runUnderstandingStage(
                conversation: $conversation,
                messageText: $messageText,
                knowledgeHits: $knowledgeHits,
                contextPayload: $contextPayload,
                understandingEngine: $understandingEngine,
            );

            $aiContext['understanding_result'] = $understandingResult->toArray();
            $aiContext['understanding_runtime'] = $understandingRuntimeMeta;

            // ── 3.5 Adapt understanding + legacy fallback if required ──────
            $aiContext['pipeline_stage'] = 'understanding_adaptation';

            [$intentResult, $entityResult, $adaptedUnderstanding] = $this->adaptUnderstandingStage(
                conversation: $conversation,
                message: $message,
                aiContext: $aiContext,
                understandingResult: $understandingResult,
                understandingRuntimeMeta: $understandingRuntimeMeta,
                understandingAdapter: $understandingAdapter,
                intentClassifier: $intentClassifier,
                entityExtractor: $entityExtractor,
            );

            $aiContext['intent_result'] = $intentResult;
            $aiContext['entity_result'] = $entityResult;
            $aiContext['understanding_meta'] = $adaptedUnderstanding['meta'] ?? [];
            $aiContext['understanding_runtime'] = $adaptedUnderstanding['meta']['llm_runtime'] ?? $understandingRuntimeMeta;

            WaLog::debug('[Job:ProcessIncoming] AI:understanding ADAPTED', [
                'conversation_id' => $conversation->id,
                'intent' => $intentResult['intent'] ?? null,
                'confidence' => $intentResult['confidence'] ?? null,
                'runtime_health' => $intentResult['runtime_health'] ?? null,
                'llm_model' => $intentResult['model_used'] ?? null,
                'llm_provider' => $intentResult['provider'] ?? null,
                'llm_status' => $intentResult['runtime_status'] ?? null,
                'llm_degraded_mode' => $intentResult['degraded_mode'] ?? null,
                'llm_schema_valid' => $intentResult['schema_valid'] ?? null,
                'llm_used_fallback_model' => $intentResult['used_fallback_model'] ?? null,
            ]);

            $aiContext['pipeline_stage'] = 'policy_and_faq';

            [$intentResult, $entityResult, $policyGuardResult, $policyStageMeta] = $this->runPolicyAndFaqStage(
                conversation: $conversation,
                message: $message,
                messageText: $messageText,
                contextPayload: $contextPayload,
                customer: $customer,
                intentResult: $intentResult,
                entityResult: $entityResult,
                crmSnapshot: $crmSnapshot,
                knowledgeHits: $knowledgeHits,
                policyGuard: $policyGuard,
                crmSnapshotService: $crmSnapshotService,
                faqResolver: $faqResolver,
            );

            $crmSnapshot = $policyStageMeta['crm_snapshot'] ?? $crmSnapshot;
            $faqResult = $policyStageMeta['faq_result'] ?? [];

            $aiContext['crm_context'] = $crmSnapshot;
            $aiContext['faq_result'] = $faqResult;
            $aiContext['intent_result'] = $intentResult;
            $aiContext['entity_result'] = $entityResult;
            $aiContext['policy_guard'] = $policyGuardResult['meta'] ?? [];
            $aiContext['understanding_runtime'] = $adaptedUnderstanding['meta']['llm_runtime'] ?? $understandingRuntimeMeta;

            // ── 4. Entity payload prepared from understanding ───────────────
            $stepStart = (int) round(microtime(true) * 1000);
            WaLog::debug('[Job:ProcessIncoming] AI:extraction START', [
                'conversation_id' => $conversation->id,
            ]);
            $entityResult = $aiContext['entity_result'] ?? $entityResult;
            $aiContext['entity_result'] = $entityResult;
            WaLog::debug('[Job:ProcessIncoming] AI:extraction END', [
                'conversation_id' => $conversation->id,
                'entity_keys' => array_keys($entityResult),
                'duration_ms' => (int) round(microtime(true) * 1000) - $stepStart,
            ]);

            // ── 5. Reply generation deferred until action is chosen ─────────
            $stepStart = (int) round(microtime(true) * 1000);
            WaLog::debug('[Job:ProcessIncoming] AI:reply DEFERRED', [
                'conversation_id' => $conversation->id,
            ]);
            $replyResult = [
                'text' => '',
                'is_fallback' => false,
                'used_knowledge' => false,
                'used_faq' => false,
            ];
            WaLog::info('[Job:ProcessIncoming] AI:reply DEFERRED', [
                'conversation_id' => $conversation->id,
                'is_fallback' => $replyResult['is_fallback'] ?? false,
                'source' => $replyResult['meta']['source'] ?? null,
                'used_faq' => $replyResult['used_faq'] ?? false,
                'used_knowledge' => $replyResult['used_knowledge'] ?? false,
                'duration_ms' => (int) round(microtime(true) * 1000) - $stepStart,
            ]);

            // ── 6. Summary deferred until final response is chosen ──────────
            $stepStart = (int) round(microtime(true) * 1000);
            $summaryResult = ['summary' => ''];
            WaLog::debug('[Job:ProcessIncoming] AI:summary DEFERRED', [
                'conversation_id' => $conversation->id,
                'has_summary' => ! empty($summaryResult['summary']),
                'duration_ms' => (int) round(microtime(true) * 1000) - $stepStart,
            ]);

            // ── 7. Rule-guided validation + business action selection ───────
            $aiContext['pipeline_stage'] = 'business_action_selection';

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

            $crmSnapshot = $crmSnapshotService->build(
                customer: $customer,
                conversation: $conversation,
                booking: $booking,
                contextPayload: $contextPayload->toArray(),
                intentResult: $intentResult,
                entityResult: $entityResult,
                bookingDecision: $bookingDecision,
            );
            $aiContext['crm_context'] = $crmSnapshot;

            $replyTemplateRequiresComposition = $this->shouldComposeAiReply($finalReply);
            $groundedReplyUsed = false;

            if ($replyTemplateRequiresComposition) {
                $aiContext['intent_result'] = $intentResult;
                $aiContext['entity_result'] = $entityResult;
                $groundedFacts = $groundedFactsBuilder->build(
                    conversation: $conversation,
                    message: $message,
                    intentResult: $intentResult,
                    entityResult: $entityResult,
                    replyTemplate: $finalReply,
                    aiContext: $aiContext,
                    bookingDecision: $bookingDecision,
                    booking: $booking,
                );
                $aiContext['grounded_response_facts'] = $groundedFacts->toArray();

                $stepStart = (int) round(microtime(true) * 1000);
                WaLog::debug('[Job:ProcessIncoming] AI:grounded_reply START', [
                    'conversation_id' => $conversation->id,
                    'booking_action' => $bookingDecision['action'] ?? null,
                    'mode' => $groundedFacts->mode->value,
                ]);
                $groundedResult = $groundedComposer->compose($groundedFacts);
                $replyResult = [
                    'text' => $groundedResult->text,
                    'is_fallback' => $groundedResult->isFallback,
                    'used_knowledge' => ! empty($aiContext['knowledge_hits']),
                    'used_faq' => (bool) (($aiContext['faq_result']['matched'] ?? false) === true),
                ];

                if (trim($groundedResult->text) === '') {
                    $replyResult = $responseGenerator->generate($aiContext);
                } else {
                    $groundedReplyUsed = true;
                }
                WaLog::info('[Job:ProcessIncoming] AI:grounded_reply END', [
                    'conversation_id' => $conversation->id,
                    'is_fallback' => $replyResult['is_fallback'] ?? false,
                    'used_faq' => $replyResult['used_faq'] ?? false,
                    'used_knowledge' => $replyResult['used_knowledge'] ?? false,
                    'mode' => $groundedFacts->mode->value,
                    'duration_ms' => (int) round(microtime(true) * 1000) - $stepStart,
                ]);
            } else {
                $replyResult = $this->replyResultFromFinalReply($finalReply);
            }

            $aiContext['pipeline_stage'] = 'reply_orchestration';
            $aiContext['pre_orchestration'] = [
                'intent_result' => $intentResult,
                'entity_result' => $entityResult,
                'reply_result' => $replyResult,
                'booking_decision' => $bookingDecision,
                'policy_guard' => $policyGuardResult['meta'] ?? [],
                'faq_result' => $faqResult ?? [],
            ];

            $orchestratedReply = $replyOrchestrator->orchestrate(array_merge($aiContext, [
                'intent_result' => $intentResult,
                'reply_result' => $replyResult,
            ]));
            $intentResult = is_array($orchestratedReply['intent_result'] ?? null)
                ? $orchestratedReply['intent_result']
                : $intentResult;
            $ruleEvaluation = is_array($orchestratedReply['rule_evaluation'] ?? null)
                ? $orchestratedReply['rule_evaluation']
                : [];
            $replyResult = is_array($orchestratedReply['reply_result'] ?? null)
                ? $orchestratedReply['reply_result']
                : $replyResult;
            $replyAuditSnapshot = $replyOrchestrator->buildAuditSnapshot($orchestratedReply);
            $aiContext['intent_result'] = $intentResult;
            $aiContext['rule_evaluation'] = $ruleEvaluation;
            $aiContext['reply_orchestration'] = $replyAuditSnapshot;

            $finalReply = $this->mergeReplyResultIntoFinalReply($finalReply, $replyResult);

            if ($replyTemplateRequiresComposition && $groundedReplyUsed) {
                $finalReply['meta'] = array_merge(
                    is_array($finalReply['meta'] ?? null) ? $finalReply['meta'] : [],
                    [
                        'source' => 'grounded_response_composer',
                        'grounded_mode' => $groundedFacts->mode->value,
                    ],
                );
            }

            $stepStart = (int) round(microtime(true) * 1000);
            $summaryResult = $summaryService->summarize($conversation, $aiContext);
            WaLog::debug('[Job:ProcessIncoming] AI:summary END', [
                'conversation_id' => $conversation->id,
                'has_summary' => ! empty($summaryResult['summary']),
                'duration_ms' => (int) round(microtime(true) * 1000) - $stepStart,
            ]);

            $guardResult = $replyGuard->guardReply(
                conversation : $conversation,
                messageText  : $messageText,
                entityResult : $entityResult,
                reply        : $finalReply,
                intentResult : $intentResult,
                guardContext : [
                    'resolved_context' => $contextPayload->resolvedContext,
                ],
            );
            $finalReply = $guardResult['reply'];

            if ($guardResult['close_intent_detected']) {
                $intentResult = array_merge($intentResult, [
                    'intent' => IntentType::CloseIntent->value,
                    'confidence' => max((float) ($intentResult['confidence'] ?? 0), 0.99),
                    'reasoning_short' => 'Close intent detected after unavailable route.',
                ]);

                WaLog::info('[Job:ProcessIncoming] Close intent detected — conversation will be closed politely', [
                    'conversation_id' => $conversation->id,
                    'message_id' => $message->id,
                    'text_preview' => $message->textPreview(80),
                ]);
            }

            if ($guardResult['unavailable_repeat_blocked']) {
                WaLog::info('[Job:ProcessIncoming] Unavailable route no-repeat guard applied', [
                    'conversation_id' => $conversation->id,
                    'message_id' => $message->id,
                    'booking_action' => $bookingDecision['action'] ?? null,
                    'text_preview' => $message->textPreview(80),
                ]);
            }

            if ($guardResult['state_repeat_rewritten'] ?? false) {
                WaLog::info('[Job:ProcessIncoming] Booking prompt rewritten to short reminder', [
                    'conversation_id' => $conversation->id,
                    'message_id' => $message->id,
                    'reply_action' => $finalReply['meta']['action'] ?? null,
                    'text_preview' => mb_substr((string) ($finalReply['text'] ?? ''), 0, 80),
                ]);
            }

            // ── 9. Persist all results ──────────────────────────────────────
            $orchestrationSnapshot = $replyOrchestrator->buildFinalSnapshot(
                intentResult: $intentResult,
                entityResult: $entityResult,
                replyResult: $finalReply,
                bookingDecision: $bookingDecision,
            );
            $orchestrationSnapshot['rule_hits'] = is_array($ruleEvaluation['rule_hits'] ?? null)
                ? array_values($ruleEvaluation['rule_hits'])
                : [];
            $orchestrationSnapshot['reply_orchestration'] = $replyAuditSnapshot;
            $orchestrationSnapshot = $this->enrichOrchestrationSnapshot(
                snapshot: $orchestrationSnapshot,
                conversation: $conversation,
                message: $message,
                crmSnapshot: $crmSnapshot,
                bookingDecision: $bookingDecision,
                knowledgeHits: $knowledgeHits,
                faqResult: $faqResult ?? null,
                finalReply: $finalReply,
            );

            $aiContext['pipeline_stage'] = 'final_reply_hardening';

            $finalReply = $replyOrchestrator->finalizeReplyWithHardening(
                replyDraft: $finalReply,
                context: $aiContext,
                intentResult: $intentResult,
                snapshot: $orchestrationSnapshot,
                knowledgeHits: $knowledgeHits,
                faqResult: $faqResult ?? null,
            );

            $replyResult = array_merge(
                $this->replyResultFromFinalReply($finalReply),
                [
                    'used_knowledge' => (bool) (($replyResult['used_knowledge'] ?? false) || $knowledgeHits !== []),
                    'used_faq' => (bool) (($replyResult['used_faq'] ?? false) || ($faqResult['matched'] ?? false)),
                ],
            );

            $orchestrationSnapshot = $replyOrchestrator->buildFinalSnapshot(
                intentResult: $intentResult,
                entityResult: $entityResult,
                replyResult: $finalReply,
                bookingDecision: $bookingDecision,
            );
            $orchestrationSnapshot['rule_hits'] = is_array($ruleEvaluation['rule_hits'] ?? null)
                ? array_values($ruleEvaluation['rule_hits'])
                : [];
            $orchestrationSnapshot['reply_orchestration'] = $replyAuditSnapshot;
            $orchestrationSnapshot = $this->enrichOrchestrationSnapshot(
                snapshot: $orchestrationSnapshot,
                conversation: $conversation,
                message: $message,
                crmSnapshot: $crmSnapshot,
                bookingDecision: $bookingDecision,
                knowledgeHits: $knowledgeHits,
                faqResult: $faqResult ?? null,
                finalReply: $finalReply,
            );
            $hallucinationGuardResult['meta'] = $this->buildHallucinationGuardMeta(
                finalReply: $finalReply,
                crmSnapshot: $crmSnapshot,
                orchestrationSnapshot: $orchestrationSnapshot,
            );
            $aiContext['hallucination_guard'] = $hallucinationGuardResult['meta'];
            $aiContext['reply_orchestration'] = $orchestrationSnapshot;
            $aiContext['decision_trace'] = $this->buildDecisionTraceSeed(
                policyGuardMeta: is_array($policyGuardResult['meta'] ?? null) ? $policyGuardResult['meta'] : [],
                hallucinationGuardMeta: $hallucinationGuardResult['meta'],
                orchestrationSnapshot: $orchestrationSnapshot,
                finalReply: $finalReply,
                crmSnapshot: $crmSnapshot,
                intentResult: $intentResult,
                entityResult: $entityResult,
                understandingRuntime: is_array($aiContext['understanding_runtime'] ?? null) ? $aiContext['understanding_runtime'] : [],
                bookingDecision: $bookingDecision,
                faqResult: $faqResult ?? null,
            );

            $orchestrationSnapshot['decision_trace_id'] = $aiContext['decision_trace']['trace_id'] ?? WaLog::traceId();
            $orchestrationSnapshot['decision_trace_version'] = $aiContext['decision_trace']['version'] ?? 'job_trace_v2';

            $outboundMessage = $this->persistResults(
                conversation        : $conversation,
                message             : $message,
                conversationManager : $conversationManager,
                replyGuard          : $replyGuard,
                intentResult        : $intentResult,
                entityResult        : $entityResult,
                resolvedContext     : $contextPayload->resolvedContext,
                summaryResult       : $summaryResult,
                finalReply          : $finalReply,
                booking             : $booking,
                guardResult         : $guardResult,
                bookingDecision     : $bookingDecision,
            );

            // ── 9.5 Dispatch WhatsApp send job for the bot reply ────────────
            if ($outboundMessage !== null) {
                $outboundRouter->dispatch($outboundMessage, WaLog::traceId());
            }

            if (($guardResult['close_conversation'] ?? false) || (($finalReply['meta']['close_conversation'] ?? false) === true)) {
                $conversationManager->close($conversation);

                WaLog::info('[Job:ProcessIncoming] Conversation closed after close intent', [
                    'conversation_id' => $conversation->id,
                    'message_id' => $message->id,
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
            $this->logLearningTurnSafely(
                learningSignalLogger: $learningSignalLogger,
                payload: new LearningSignalPayload(
                    conversationId: $conversation->id,
                    inboundMessageId: $message->id,
                    userMessage: $messageText,
                    contextSummary: trim((string) ($summaryResult['summary'] ?? '')) !== ''
                        ? (string) $summaryResult['summary']
                        : $contextPayload?->conversationSummary,
                    contextSnapshot: array_merge(
                        $contextPayload?->toArray() ?? [],
                        [
                            'crm_context' => $crmSnapshot,
                            'orchestration' => $orchestrationSnapshot,
                            'understanding_runtime' => $aiContext['understanding_runtime'] ?? [],
                        ],
                    ),
                    understandingResult: is_array($aiContext['understanding_result'] ?? null)
                        ? $aiContext['understanding_result']
                        : [],
                    chosenAction: $this->deriveLearningAction($bookingDecision, $finalReply, $policyGuardResult),
                    groundedFacts: is_array($aiContext['grounded_response_facts'] ?? null)
                        ? $aiContext['grounded_response_facts']
                        : null,
                    finalResponse: (string) ($finalReply['text'] ?? ''),
                    finalResponseMeta: is_array($finalReply['meta'] ?? null) ? $finalReply['meta'] : [],
                    fallbackUsed: (bool) ($finalReply['is_fallback'] ?? false),
                    handoffHappened: $this->didHandoffHappen($conversation, $intentResult, $finalReply, $bookingDecision),
                    adminTakeoverActive: $conversation->isAdminTakeover(),
                    outboundSent: $outboundMessage !== null,
                    outboundMessageId: $outboundMessage?->id,
                    classifierContext: [
                        'policy_guard' => is_array($policyGuardResult['meta'] ?? null) ? $policyGuardResult['meta'] : [],
                        'hallucination_guard' => is_array($hallucinationGuardResult['meta'] ?? null) ? $hallucinationGuardResult['meta'] : [],
                        'reply_guard' => $guardResult,
                        'rule_evaluation' => $ruleEvaluation,
                        'reply_orchestration' => $replyAuditSnapshot,
                        'final_orchestration' => $orchestrationSnapshot,
                        'entity_result' => $entityResult,
                        'reply_result' => $replyResult,
                        'understanding_runtime' => $aiContext['understanding_runtime'] ?? [],
                    ],
                ),
            );

            $audit->recordAiOrchestration(
                conversationId: $conversation->id,
                message: 'AI orchestration final snapshot recorded.',
                snapshot: $orchestrationSnapshot,
            );

            $aiContext['pipeline_stage'] = 'crm_writeback';

            $crmWritebackSnapshot = $this->buildCrmWritebackSnapshot(
                conversation: $conversation,
                message: $message,
                crmSnapshot: $crmSnapshot,
                orchestrationSnapshot: $orchestrationSnapshot,
                decisionTrace: is_array($aiContext['decision_trace'] ?? null) ? $aiContext['decision_trace'] : [],
                understandingRuntime: is_array($aiContext['understanding_runtime'] ?? null) ? $aiContext['understanding_runtime'] : [],
                policyGuardMeta: is_array($policyGuardResult['meta'] ?? null) ? $policyGuardResult['meta'] : [],
                hallucinationGuardMeta: is_array($hallucinationGuardResult['meta'] ?? null) ? $hallucinationGuardResult['meta'] : [],
                intentResult: $intentResult,
                entityResult: $entityResult,
                bookingDecision: $bookingDecision,
                faqResult: $faqResult ?? null,
            );

            $this->runCrmOperations(
                conversation   : $conversation,
                booking        : $booking,
                intentResult   : $intentResult,
                summaryResult  : $summaryResult,
                finalReply     : $finalReply,
                contextSnapshot: $crmWritebackSnapshot,
                crmWriteback   : $crmWriteback,
            );

            $durationMs = (int) round(microtime(true) * 1000) - $jobStartMs;

            WaLog::info('[Job:ProcessIncoming] Pipeline complete', [
                'conversation_id' => $conversation->id,
                'message_id' => $message->id,
                'job_trace_id' => WaLog::traceId(),
                'decision_trace_id' => $aiContext['decision_trace']['trace_id'] ?? null,
                'intent' => $intentResult['intent'],
                'confidence' => $intentResult['confidence'],
                'booking_action' => $bookingDecision['action'] ?? null,
                'crm_snapshot_present' => ! empty($crmSnapshot),
                'crm_snapshot_sections' => array_keys(is_array($crmSnapshot) ? $crmSnapshot : []),
                'booking_decision_present' => ! empty($bookingDecision),
                'is_fallback' => $finalReply['is_fallback'],
                'used_knowledge' => $replyResult['used_knowledge'] ?? false,
                'used_faq' => $replyResult['used_faq'] ?? false,
                'knowledge_count' => count($knowledgeHits),
                'outbound_id' => $outboundMessage?->id,
                'outbound_skipped' => $outboundMessage === null,
                'duration_ms' => $durationMs,
            ]);
        } catch (\Throwable $e) {
            $durationMs = (int) round(microtime(true) * 1000) - $jobStartMs;

            $aiContext['pipeline_stage'] = $aiContext['pipeline_stage'] ?? 'unknown_failure_stage';

            WaLog::error('[Job:ProcessIncoming] Pipeline error — emergency fallback triggered', [
                'conversation_id' => $this->conversationId,
                'message_id' => $this->messageId,
                'pipeline_stage' => $aiContext['pipeline_stage'] ?? null,
                'error' => $e->getMessage(),
                'file' => $e->getFile().':'.$e->getLine(),
                'duration_ms' => $durationMs,
                'trace' => $e->getTraceAsString(),
            ]);

            $emergencyOutbound = $this->saveEmergencyFallback(
                conversation: $conversation,
                conversationManager: $conversationManager,
                outboundRouter: $outboundRouter,
            );

            $this->logLearningTurnSafely(
                learningSignalLogger: $learningSignalLogger,
                payload: new LearningSignalPayload(
                    conversationId: $conversation->id,
                    inboundMessageId: $message->id,
                    userMessage: (string) ($message->message_text ?? ''),
                    contextSummary: trim((string) ($summaryResult['summary'] ?? '')) !== ''
                        ? (string) $summaryResult['summary']
                        : $conversation->summary,
                    contextSnapshot: array_merge(
                        $contextPayload?->toArray() ?? [],
                        ['crm_context' => $crmSnapshot],
                    ),
                    understandingResult: is_array($aiContext['understanding_result'] ?? null)
                        ? $aiContext['understanding_result']
                        : [],
                    chosenAction: 'emergency_fallback',
                    groundedFacts: is_array($aiContext['grounded_response_facts'] ?? null)
                        ? $aiContext['grounded_response_facts']
                        : null,
                    finalResponse: $this->emergencyFallbackText(),
                    finalResponseMeta: [
                        'source' => 'emergency_fallback',
                        'exception' => class_basename($e),
                    ],
                    fallbackUsed: true,
                    handoffHappened: false,
                    adminTakeoverActive: $conversation->isAdminTakeover(),
                    outboundSent: $emergencyOutbound !== null,
                    outboundMessageId: $emergencyOutbound?->id,
                    classifierContext: [
                        'policy_guard' => is_array($policyGuardResult['meta'] ?? null) ? $policyGuardResult['meta'] : [],
                        'hallucination_guard' => is_array($hallucinationGuardResult['meta'] ?? null) ? $hallucinationGuardResult['meta'] : [],
                        'reply_guard' => $guardResult,
                        'entity_result' => $entityResult,
                        'reply_result' => $replyResult,
                    ],
                ),
            );

            throw $e;
        } finally {
            rescue(static function () use ($conversationLock): void {
                $conversationLock->release();
            }, report: false);
        }
    }

    // -------------------------------------------------------------------------
    // Tahap 10: Knowledge helpers
    // -------------------------------------------------------------------------

    /**
     * @param  array<int, array<string, mixed>>  $knowledgeHits
     * @return array{0: mixed, 1: array<string, mixed>}
     */
    private function runUnderstandingStage(
        Conversation $conversation,
        string $messageText,
        array $knowledgeHits,
        \App\Data\Chatbot\ConversationContextPayload $contextPayload,
        LlmUnderstandingEngine $understandingEngine,
    ): array {
        $stepStart = (int) round(microtime(true) * 1000);

        WaLog::debug('[Job:ProcessIncoming] AI:understanding START', [
            'conversation_id' => $conversation->id,
            'message_preview' => mb_substr($messageText, 0, 60),
            'knowledge_hits' => count($knowledgeHits),
            'understanding_mode' => 'llm_first_with_crm_hints_only',
        ]);

        $understandingResult = $understandingEngine->understandFromContext(
            contextPayload: $contextPayload,
            allowedIntents: IntentType::cases(),
        );

        $runtimeMeta = $understandingEngine->lastRuntimeMeta();

        WaLog::info('[Job:ProcessIncoming] AI:understanding END', [
            'conversation_id' => $conversation->id,
            'intent' => $understandingResult->intent,
            'confidence' => $understandingResult->confidence,
            'needs_clarification' => $understandingResult->needsClarification,
            'handoff_recommended' => $understandingResult->handoffRecommended,
            'understanding_mode' => 'llm_first_with_crm_hints_only',
            'llm_model' => $runtimeMeta['model'] ?? null,
            'llm_status' => $runtimeMeta['status'] ?? null,
            'llm_degraded_mode' => $runtimeMeta['degraded_mode'] ?? null,
            'llm_schema_valid' => $runtimeMeta['schema_valid'] ?? null,
            'llm_used_fallback_model' => $runtimeMeta['used_fallback_model'] ?? null,
            'duration_ms' => (int) round(microtime(true) * 1000) - $stepStart,
        ]);

        return [$understandingResult, $runtimeMeta];
    }

    /**
     * @param  array<string, mixed>  $aiContext
     * @param  array<string, mixed>  $understandingRuntimeMeta
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>}
     */
    private function adaptUnderstandingStage(
        Conversation $conversation,
        ConversationMessage $message,
        array $aiContext,
        mixed $understandingResult,
        array $understandingRuntimeMeta,
        UnderstandingResultAdapterService $understandingAdapter,
        IntentClassifierService $intentClassifier,
        EntityExtractorService $entityExtractor,
    ): array {
        $legacyIntentResult = [];
        $legacyEntityResult = [];

        if ($understandingAdapter->needsLegacyFallback($understandingResult)) {
            WaLog::warning('[Job:ProcessIncoming] Understanding fallback triggered - using legacy backup', [
                'conversation_id' => $conversation->id,
                'message_id' => $message->id,
            ]);

            $stepStart = (int) round(microtime(true) * 1000);
            $legacyIntentResult = $intentClassifier->classify($aiContext);
            WaLog::info('[Job:ProcessIncoming] AI:intent fallback END', [
                'conversation_id' => $conversation->id,
                'intent' => $legacyIntentResult['intent'] ?? null,
                'confidence' => $legacyIntentResult['confidence'] ?? null,
                'duration_ms' => (int) round(microtime(true) * 1000) - $stepStart,
            ]);

            $stepStart = (int) round(microtime(true) * 1000);
            $legacyEntityResult = $entityExtractor->extract(array_merge($aiContext, [
                'intent_result' => $legacyIntentResult,
            ]));
            WaLog::info('[Job:ProcessIncoming] AI:extraction fallback END', [
                'conversation_id' => $conversation->id,
                'entity_keys' => array_keys($legacyEntityResult),
                'duration_ms' => (int) round(microtime(true) * 1000) - $stepStart,
            ]);
        }

        $adaptedUnderstanding = $understandingAdapter->adapt(
            understanding: $understandingResult,
            legacyIntentResult: $legacyIntentResult,
            legacyEntityResult: $legacyEntityResult,
            llmRuntimeMeta: $understandingRuntimeMeta,
        );

        return [
            $adaptedUnderstanding['intent_result'] ?? [],
            $adaptedUnderstanding['entity_result'] ?? [],
            $adaptedUnderstanding,
        ];
    }

    /**
     * @param  array<string, mixed>  $intentResult
     * @param  array<string, mixed>  $entityResult
     * @param  array<string, mixed>  $crmSnapshot
     * @param  array<int, array<string, mixed>>  $knowledgeHits
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>, 3: array<string, mixed>}
     */
    private function runPolicyAndFaqStage(
        Conversation $conversation,
        ConversationMessage $message,
        string $messageText,
        \App\Data\Chatbot\ConversationContextPayload $contextPayload,
        Customer $customer,
        array $intentResult,
        array $entityResult,
        array $crmSnapshot,
        array $knowledgeHits,
        PolicyGuardService $policyGuard,
        CrmOrchestrationSnapshotService $crmSnapshotService,
        FaqResolverService $faqResolver,
    ): array {
        $updatedCrmSnapshot = $crmSnapshotService->build(
            customer: $customer,
            conversation: $conversation,
            booking: null,
            contextPayload: $contextPayload->toArray(),
            intentResult: $intentResult,
            entityResult: $entityResult,
            bookingDecision: null,
        );

        $faqResult = $faqResolver->resolve($messageText, $knowledgeHits);

        $policyGuardResult = $policyGuard->guard(
            conversation: $conversation,
            intentResult: $intentResult,
            entityResult: $entityResult,
            understandingResult: [],
            resolvedContext: $contextPayload->resolvedContext,
            conversationState: $contextPayload->conversationState,
            crmContext: $updatedCrmSnapshot,
        );

        if ($faqResult['matched'] ?? false) {
            AiLog::where('conversation_id', $conversation->id)
                ->where('message_id', $message->id)
                ->whereIn('task_type', ['reply_generation', 'grounded_response_composition'])
                ->latest()
                ->limit(1)
                ->update(['quality_label' => 'faq_direct']);

            WaLog::info('[Job:ProcessIncoming] FAQ matched — LLM reply may be skipped', [
                'conversation_id' => $conversation->id,
                'faq_id' => $faqResult['id'] ?? null,
                'score' => $faqResult['score'] ?? null,
            ]);
        }

        return [
            $policyGuardResult['intent_result'] ?? $intentResult,
            $policyGuardResult['entity_result'] ?? $entityResult,
            $policyGuardResult,
            [
                'crm_snapshot' => $updatedCrmSnapshot,
                'faq_result' => $faqResult,
            ],
        ];
    }

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
     * @param  array<string, mixed>  $intentResult
     * @param  array<string, mixed>  $replyResult
     * @param  array<string, mixed>  $faqResult
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
                    ->whereIn('task_type', ['message_understanding', 'intent_classification'])
                    ->latest()
                    ->limit(1)
                    ->update(['quality_label' => 'low_confidence']);
            }

            // ── Reply quality label ─────────────────────────────────────────
            if ($faqResult['matched'] ?? false) {
                // FAQ direct: no LLM call was made — create a log row to record this event
                if ($updated === 0 && config('chatbot.ai_quality.store_knowledge_hits', true)) {
                    AiLog::writeLog('reply_generation', 'success', [
                        'conversation_id' => $conversationId,
                        'message_id' => $messageId,
                        'quality_label' => 'faq_direct',
                        'knowledge_hits' => ! empty($knowledgeHits) ? $knowledgeHits : null,
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
                        ->whereIn('task_type', ['reply_generation', 'grounded_response_composition'])
                        ->latest()
                        ->limit(1)
                        ->update(['quality_label' => $replyLabel]);
                }
            }
        } catch (\Throwable $e) {
            WaLog::warning('[Job:ProcessIncoming] quality label update failed (non-fatal)', [
                'conversation_id' => $conversationId,
                'message_id' => $messageId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    // -------------------------------------------------------------------------
    // CRM layer
    // -------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $crmSnapshot
     * @param  array<string, mixed>  $orchestrationSnapshot
     * @param  array<string, mixed>  $decisionTrace
     * @param  array<string, mixed>  $understandingRuntime
     * @param  array<string, mixed>  $policyGuardMeta
     * @param  array<string, mixed>  $hallucinationGuardMeta
     * @param  array<string, mixed>  $intentResult
     * @param  array<string, mixed>  $entityResult
     * @param  array<string, mixed>|null  $bookingDecision
     * @param  array<string, mixed>|null  $faqResult
     * @return array<string, mixed>
     */
    private function buildCrmWritebackSnapshot(
        Conversation $conversation,
        ConversationMessage $message,
        array $crmSnapshot,
        array $orchestrationSnapshot,
        array $decisionTrace,
        array $understandingRuntime,
        array $policyGuardMeta,
        array $hallucinationGuardMeta,
        array $intentResult,
        array $entityResult,
        ?array $bookingDecision = null,
        ?array $faqResult = null,
    ): array {
        return [
            'job_trace_id' => WaLog::traceId(),
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
            'pipeline_stage' => 'crm_writeback',
            'crm_context' => $crmSnapshot,
            'orchestration' => $orchestrationSnapshot,
            'decision_trace' => $decisionTrace,
            'understanding_runtime' => $understandingRuntime,
            'policy_guard' => $policyGuardMeta,
            'hallucination_guard' => $hallucinationGuardMeta,
            'intent_result' => [
                'intent' => $intentResult['intent'] ?? null,
                'confidence' => $intentResult['confidence'] ?? null,
                'reasoning_short' => $intentResult['reasoning_short'] ?? null,
                'needs_clarification' => (bool) ($intentResult['needs_clarification'] ?? false),
                'handoff_recommended' => (bool) ($intentResult['handoff_recommended'] ?? false),
            ],
            'entity_result' => [
                'keys' => array_values(array_keys($entityResult)),
                'count' => count($entityResult),
            ],
            'booking_decision' => [
                'action' => $bookingDecision['action'] ?? null,
                'status' => $bookingDecision['status'] ?? null,
            ],
            'faq_result' => [
                'matched' => (bool) ($faqResult['matched'] ?? false),
                'id' => $faqResult['id'] ?? null,
                'score' => $faqResult['score'] ?? null,
            ],
        ];
    }

    private function runCrmOperations(
        Conversation $conversation,
        ?BookingRequest $booking,
        array $intentResult,
        array $summaryResult,
        array $finalReply,
        array $contextSnapshot,
        CRMWritebackService $crmWriteback,
    ): void {
        if (! config('chatbot.crm.enabled', true)) {
            WaLog::info('[Job:ProcessIncoming] CRM writeback skipped — disabled by config', [
                'conversation_id' => $conversation->id,
                'message_id' => $contextSnapshot['message_id'] ?? null,
                'job_trace_id' => $contextSnapshot['job_trace_id'] ?? null,
            ]);

            return;
        }

        try {
            $result = $crmWriteback->syncDecision(
                conversation: $conversation,
                booking: $booking,
                intentResult: $intentResult,
                summaryResult: $summaryResult,
                finalReply: $finalReply,
                contextSnapshot: $contextSnapshot,
            );

            WaLog::info('[Job:ProcessIncoming] CRM writeback dispatched', [
                'conversation_id' => $conversation->id,
                'message_id' => $contextSnapshot['message_id'] ?? null,
                'job_trace_id' => $contextSnapshot['job_trace_id'] ?? null,
                'pipeline_stage' => $contextSnapshot['pipeline_stage'] ?? null,
                'intent' => $contextSnapshot['intent_result']['intent'] ?? ($intentResult['intent'] ?? null),
                'intent_confidence' => $contextSnapshot['intent_result']['confidence'] ?? ($intentResult['confidence'] ?? null),
                'result_status' => $result['status'] ?? null,
                'lead_stage' => $result['lead_stage'] ?? null,
                'needs_escalation' => $result['needs_escalation'] ?? false,
                'tags' => $result['tags'] ?? [],
                'contact_sync' => $result['contact_sync']['status'] ?? null,
                'summary_sync' => $result['summary_sync']['status'] ?? null,
                'decision_note_sync' => $result['decision_note_sync']['status'] ?? null,
                'escalation_sync' => $result['escalation_sync']['status'] ?? null,
                'decision_trace_id' => $result['decision_trace']['trace_id'] ?? ($contextSnapshot['decision_trace']['trace_id'] ?? null),
                'understanding_model' => $contextSnapshot['understanding_runtime']['model'] ?? null,
                'understanding_status' => $contextSnapshot['understanding_runtime']['status'] ?? null,
                'policy_action' => $contextSnapshot['policy_guard']['action'] ?? null,
                'hallucination_action' => $contextSnapshot['hallucination_guard']['action'] ?? null,
                'crm_context_sections' => $contextSnapshot['decision_trace']['crm']['context_sections'] ?? [],
            ]);
        } catch (\Throwable $e) {
            WaLog::error('[Job:ProcessIncoming] CRM integrated writeback failed (non-fatal)', [
                'conversation_id' => $conversation->id,
                'message_id' => $contextSnapshot['message_id'] ?? null,
                'job_trace_id' => $contextSnapshot['job_trace_id'] ?? null,
                'pipeline_stage' => $contextSnapshot['pipeline_stage'] ?? null,
                'decision_trace_id' => $contextSnapshot['decision_trace']['trace_id'] ?? null,
                'error' => $e->getMessage(),
                'file' => $e->getFile().':'.$e->getLine(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $crmSnapshot
     * @param  array<string, mixed>|null  $bookingDecision
     * @param  array<int, mixed>  $knowledgeHits
     * @param  array<string, mixed>|null  $faqResult
     * @param  array<string, mixed>  $finalReply
     * @return array<string, mixed>
     */
    private function enrichOrchestrationSnapshot(
        array $snapshot,
        \App\Models\Conversation $conversation,
        \App\Models\ConversationMessage $message,
        array $crmSnapshot = [],
        ?array $bookingDecision = null,
        array $knowledgeHits = [],
        ?array $faqResult = null,
        array $finalReply = [],
    ): array {
        $snapshot['conversation_id'] = $conversation->id;
        $snapshot['message_id'] = $message->id;
        $snapshot['crm_context_present'] = ! empty($crmSnapshot);
        $snapshot['crm_snapshot_present'] = ! empty($crmSnapshot);
        $snapshot['crm_snapshot_sections'] = array_keys($crmSnapshot);
        $snapshot['booking_decision_present'] = ! empty($bookingDecision);
        $snapshot['knowledge_hits_count'] = count($knowledgeHits);
        $snapshot['used_faq'] = (bool) ($faqResult['matched'] ?? false);
        $snapshot['used_knowledge'] = $knowledgeHits !== [];
        $snapshot['hardening_applied'] = (bool) ($finalReply['meta']['hardening_applied'] ?? false);
        $snapshot['grounding_source'] = $finalReply['meta']['grounding_source'] ?? null;
        $snapshot['hallucination_risk_level'] = $finalReply['meta']['hallucination_risk_level'] ?? null;
        $snapshot['policy_violations'] = is_array($finalReply['meta']['policy_violations'] ?? null)
            ? $finalReply['meta']['policy_violations']
            : [];

        return $snapshot;
    }


    /**
     * @param  array<string, mixed>  $finalReply
     * @param  array<string, mixed>  $crmSnapshot
     * @param  array<string, mixed>  $orchestrationSnapshot
     * @return array<string, mixed>
     */
    private function buildHallucinationGuardMeta(
        array $finalReply,
        array $crmSnapshot = [],
        array $orchestrationSnapshot = [],
    ): array {
        $replyMeta = is_array($finalReply['meta'] ?? null) ? $finalReply['meta'] : [];

        $crmSections = array_keys(array_filter([
            'customer' => $crmSnapshot['customer'] ?? null,
            'hubspot' => $crmSnapshot['hubspot'] ?? null,
            'lead_pipeline' => $crmSnapshot['lead_pipeline'] ?? null,
            'conversation' => $crmSnapshot['conversation'] ?? null,
            'booking' => $crmSnapshot['booking'] ?? null,
            'escalation' => $crmSnapshot['escalation'] ?? null,
            'business_flags' => $crmSnapshot['business_flags'] ?? null,
        ], static fn (mixed $value): bool => is_array($value) ? $value !== [] : ! empty($value)));

        $usedCrmFacts = [];
        foreach ((array) ($replyMeta['used_crm_facts'] ?? []) as $fact) {
            if (is_scalar($fact)) {
                $text = trim((string) $fact);
                if ($text !== '') {
                    $usedCrmFacts[] = $text;
                }
            }
        }

        foreach ($crmSections as $section) {
            $usedCrmFacts[] = 'crm.'.$section;
        }

        $usedCrmFacts = array_values(array_unique($usedCrmFacts));

        $riskLevel = $replyMeta['hallucination_risk_level'] ?? null;
        $blocked = (bool) ($replyMeta['hallucination_blocked'] ?? false);

        if (! $blocked && $riskLevel === 'high') {
            $blocked = true;
        }

        return [
            'guard_group' => 'hallucination',
            'action' => (string) (
                $replyMeta['hallucination_action']
                ?? ($blocked ? 'blocked_high_risk' : 'allow')
            ),
            'blocked' => $blocked,
            'reason' => $replyMeta['hallucination_reason'] ?? null,
            'risk_level' => $riskLevel,
            'risk_flags' => is_array($replyMeta['hallucination_risk_flags'] ?? null)
                ? array_values($replyMeta['hallucination_risk_flags'])
                : [],
            'grounding_source' => $replyMeta['grounding_source'] ?? null,
            'crm_grounding_present' => $crmSections !== [],
            'crm_grounding_sections' => $crmSections,
            'used_crm_facts' => $usedCrmFacts,
            'decision_trace_hallucination' => is_array($replyMeta['decision_trace_hallucination'] ?? null)
                ? $replyMeta['decision_trace_hallucination']
                : [
                    'action' => (string) (
                        $replyMeta['hallucination_action']
                        ?? ($blocked ? 'blocked_high_risk' : 'allow')
                    ),
                    'blocked' => $blocked,
                    'reason' => $replyMeta['hallucination_reason'] ?? null,
                    'risk_level' => $riskLevel,
                    'risk_flags' => is_array($replyMeta['hallucination_risk_flags'] ?? null)
                        ? array_values($replyMeta['hallucination_risk_flags'])
                        : [],
                    'grounding_source' => $replyMeta['grounding_source'] ?? null,
                    'crm_grounding_present' => $crmSections !== [],
                    'crm_grounding_sections' => $crmSections,
                    'used_crm_facts' => $usedCrmFacts,
                    'orchestration_reply_force_handoff' => (bool) ($orchestrationSnapshot['reply_force_handoff'] ?? false),
                    'orchestration_used_faq' => (bool) ($orchestrationSnapshot['used_faq'] ?? false),
                    'orchestration_used_knowledge' => (bool) ($orchestrationSnapshot['used_knowledge'] ?? false),
                ],
        ];
    }

    /**
     * @param  array<string, mixed>  $policyGuardMeta
     * @param  array<string, mixed>  $hallucinationGuardMeta
     * @param  array<string, mixed>  $orchestrationSnapshot
     * @param  array<string, mixed>  $finalReply
     * @param  array<string, mixed>  $crmSnapshot
     * @return array<string, mixed>
     */
    private function buildDecisionTraceSeed(
        array $policyGuardMeta = [],
        array $hallucinationGuardMeta = [],
        array $orchestrationSnapshot = [],
        array $finalReply = [],
        array $crmSnapshot = [],
        array $intentResult = [],
        array $entityResult = [],
        array $understandingRuntime = [],
        ?array $bookingDecision = null,
        ?array $faqResult = null,
    ): array {
        $replyMeta = is_array($finalReply['meta'] ?? null) ? $finalReply['meta'] : [];

        $crmContextSections = array_keys(array_filter(
            $crmSnapshot,
            static fn (mixed $value): bool => is_array($value) ? $value !== [] : ! empty($value)
        ));

        $usedCrmFacts = is_array($replyMeta['used_crm_facts'] ?? null)
            ? array_values(array_filter($replyMeta['used_crm_facts'], static fn ($item) => is_scalar($item) && trim((string) $item) !== ''))
            : [];

        return [
            'trace_id' => WaLog::traceId(),
            'version' => 'job_trace_v2',
            'intent' => [
                'name' => $intentResult['intent'] ?? null,
                'confidence' => isset($intentResult['confidence']) ? (float) $intentResult['confidence'] : null,
                'reasoning_short' => $intentResult['reasoning_short'] ?? null,
                'needs_clarification' => (bool) ($intentResult['needs_clarification'] ?? false),
                'handoff_recommended' => (bool) ($intentResult['handoff_recommended'] ?? false),
            ],
            'entities' => [
                'keys' => array_values(array_keys($entityResult)),
                'count' => count($entityResult),
            ],
            'understanding_runtime' => [
                'provider' => $understandingRuntime['provider'] ?? null,
                'model' => $understandingRuntime['model'] ?? null,
                'status' => $understandingRuntime['status'] ?? null,
                'degraded_mode' => $understandingRuntime['degraded_mode'] ?? null,
                'schema_valid' => $understandingRuntime['schema_valid'] ?? null,
                'used_fallback_model' => $understandingRuntime['used_fallback_model'] ?? null,
                'task_key' => $understandingRuntime['task_key'] ?? null,
                'task_type' => $understandingRuntime['task_type'] ?? null,
            ],
            'policy_guard' => $policyGuardMeta,
            'hallucination_guard' => $hallucinationGuardMeta,
            'orchestration' => [
                'reply_source' => $orchestrationSnapshot['reply_source'] ?? null,
                'reply_action' => $orchestrationSnapshot['reply_action'] ?? null,
                'reply_force_handoff' => (bool) ($orchestrationSnapshot['reply_force_handoff'] ?? false),
                'needs_human' => (bool) ($orchestrationSnapshot['needs_human'] ?? false),
                'reply_guard_action' => $orchestrationSnapshot['reply_guard_action'] ?? null,
                'used_faq' => (bool) ($faqResult['matched'] ?? false),
            ],
            'booking_decision' => [
                'action' => $bookingDecision['action'] ?? null,
                'status' => $bookingDecision['status'] ?? null,
            ],
            'final_reply' => [
                'source' => $replyMeta['source'] ?? null,
                'action' => $replyMeta['action'] ?? null,
                'force_handoff' => (bool) ($replyMeta['force_handoff'] ?? false),
                'is_fallback' => (bool) ($finalReply['is_fallback'] ?? false),
                'grounding_source' => $replyMeta['grounding_source'] ?? null,
                'hallucination_risk_level' => $replyMeta['hallucination_risk_level'] ?? null,
                'used_crm_facts' => $usedCrmFacts,
            ],
            'crm' => [
                'context_present' => ! empty($crmSnapshot),
                'context_sections' => $crmContextSections,
            ],
        ];
    }

    public function failed(\Throwable $exception): void
    {
        WaLog::critical('[Job:ProcessIncoming] permanently failed after retries', [
            'message_id' => $this->messageId,
            'conversation_id' => $this->conversationId,
            'error' => $exception->getMessage(),
            'file' => $exception->getFile().':'.$exception->getLine(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $reply
     */
    private function shouldComposeAiReply(array $reply): bool
    {
        return ($reply['meta']['source'] ?? null) === 'ai_reply'
            && (($reply['meta']['requires_composition'] ?? false) === true || trim((string) ($reply['text'] ?? '')) === '');
    }

    /**
     * @param  array<string, mixed>  $replyTemplate
     * @param  array<string, mixed>  $replyResult
     * @return array<string, mixed>
     */
    private function mergeReplyResultIntoFinalReply(array $replyTemplate, array $replyResult): array
    {
        $replyTemplate['text'] = (string) ($replyResult['text'] ?? $replyResult['reply'] ?? '');
        $replyTemplate['is_fallback'] = (bool) ($replyResult['is_fallback'] ?? false);
        $replyTemplate['tone'] = $replyResult['tone'] ?? ($replyTemplate['tone'] ?? 'ramah');
        $replyTemplate['should_escalate'] = (bool) ($replyResult['should_escalate'] ?? ($replyTemplate['should_escalate'] ?? false));
        $replyTemplate['handoff_reason'] = $replyResult['handoff_reason'] ?? ($replyTemplate['handoff_reason'] ?? null);
        $replyTemplate['next_action'] = $replyResult['next_action'] ?? ($replyTemplate['next_action'] ?? null);
        $replyTemplate['data_requests'] = is_array($replyResult['data_requests'] ?? null)
            ? $replyResult['data_requests']
            : ($replyTemplate['data_requests'] ?? []);
        $replyTemplate['used_crm_facts'] = is_array($replyResult['used_crm_facts'] ?? null)
            ? $replyResult['used_crm_facts']
            : ($replyTemplate['used_crm_facts'] ?? []);
        $replyTemplate['safety_notes'] = is_array($replyResult['safety_notes'] ?? null)
            ? $replyResult['safety_notes']
            : ($replyTemplate['safety_notes'] ?? []);
        $replyTemplate['message_type'] = $replyTemplate['message_type'] ?? 'text';
        $replyTemplate['outbound_payload'] = is_array($replyTemplate['outbound_payload'] ?? null)
            ? $replyTemplate['outbound_payload']
            : [];
        $replyTemplate['meta'] = array_merge(
            is_array($replyTemplate['meta'] ?? null) ? $replyTemplate['meta'] : [],
            is_array($replyResult['meta'] ?? null) ? $replyResult['meta'] : [],
            [
                'source' => (string) ((is_array($replyTemplate['meta'] ?? null) ? ($replyTemplate['meta']['source'] ?? null) : null) ?? 'ai_reply'),
                'decision_source' => (string) ((is_array($replyResult['meta'] ?? null) ? ($replyResult['meta']['source'] ?? null) : null) ?? ((is_array($replyTemplate['meta'] ?? null) ? ($replyTemplate['meta']['decision_source'] ?? null) : null) ?? 'unknown')),
            ],
        );

        return $replyTemplate;
    }

    /**
     * @param  array<string, mixed>  $finalReply
     * @return array{text: string, is_fallback: bool, used_knowledge: bool, used_faq: bool}
     */
    private function replyResultFromFinalReply(array $finalReply): array
    {
        return [
            'reply' => (string) ($finalReply['text'] ?? ''),
            'text' => (string) ($finalReply['text'] ?? ''),
            'tone' => $finalReply['tone'] ?? 'ramah',
            'should_escalate' => (bool) ($finalReply['should_escalate'] ?? (($finalReply['meta']['force_handoff'] ?? false) === true)),
            'handoff_reason' => $finalReply['handoff_reason'] ?? null,
            'next_action' => $finalReply['next_action'] ?? 'answer_question',
            'data_requests' => is_array($finalReply['data_requests'] ?? null) ? $finalReply['data_requests'] : [],
            'used_crm_facts' => is_array($finalReply['used_crm_facts'] ?? null) ? $finalReply['used_crm_facts'] : [],
            'safety_notes' => is_array($finalReply['safety_notes'] ?? null) ? $finalReply['safety_notes'] : [],
            'grounding_notes' => is_array($finalReply['grounding_notes'] ?? null) ? $finalReply['grounding_notes'] : [],
            'meta' => is_array($finalReply['meta'] ?? null) ? $finalReply['meta'] : [],
            'is_fallback' => (bool) ($finalReply['is_fallback'] ?? false),
            'used_knowledge' => false,
            'used_faq' => false,
        ];
    }

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
        $intentEnum = IntentType::tryFrom($intentResult['intent']);
        $isBookingRelated = $intentEnum !== null && $intentEnum->isBookingRelated();
        $existingDraft = $bookingAssistant->findExistingDraft($conversation);

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
            'booking_id' => $booking->id,
            'action' => $bookingDecision['action'],
            'booking_status' => $bookingDecision['booking_status'],
        ]);

        return [$booking, $bookingDecision];
    }

    /**
     * Persist message/conversation AI results that do not depend on whether an
     * outbound reply is eventually sent.
     *
     * @param  array{intent: string, confidence: float, reasoning_short: string}  $intentResult
     * @param  array{summary: string}  $summaryResult
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
     * @param  array<string, mixed>  $guardResult
     * @param  array<string, mixed>|null  $bookingDecision
     */
    private function persistResults(
        Conversation $conversation,
        ConversationMessage $message,
        ConversationManagerService $conversationManager,
        ConversationReplyGuardService $replyGuard,
        array $intentResult,
        array $entityResult,
        array $resolvedContext,
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
        $inboundContextFingerprint = $replyGuard->buildInboundContextFingerprint(
            messageText: (string) ($message->message_text ?? ''),
            intentResult: $intentResult,
            entityResult: $entityResult,
            resolvedContext: $resolvedContext,
        );
        $replyIdentity = is_array($guardResult['reply_identity'] ?? null)
            ? $guardResult['reply_identity']
            : $replyGuard->buildReplyIdentity($conversation, $finalReply, $inboundContextFingerprint);

        if ($this->shouldSkipDuplicateFinalReview($conversation, $finalReply)) {
            WaLog::info('[Job:ProcessIncoming] Duplicate final review skipped by review_hash', [
                'conversation_id' => $conversation->id,
                'message_id' => $message->id,
                'review_hash' => $finalReply['meta']['review_hash'] ?? null,
            ]);

            $replyGuard->rememberReplyIdentity($conversation, $replyIdentity);

            return null;
        }

        if ($replyGuard->shouldSkipRepeat($conversation, $latestOutbound, $finalReply, $replyIdentity, $inboundContextFingerprint)) {
            WaLog::info('[Job:ProcessIncoming] Anti-repeat skip — outbound identical to latest reply', [
                'conversation_id' => $conversation->id,
                'message_id' => $message->id,
                'latest_outbound_id' => $latestOutbound?->id,
                'latest_preview' => $latestOutbound?->textPreview(80),
                'candidate_preview' => mb_substr((string) $finalReply['text'], 0, 80),
                'reply_source' => $finalReply['meta']['source'] ?? null,
                'reply_action' => $finalReply['meta']['action'] ?? null,
                'outbound_fingerprint' => $replyIdentity['outbound_fingerprint'] ?? null,
                'state_response_hash' => $replyIdentity['state_response_hash'] ?? null,
                'inbound_context_fingerprint' => $replyIdentity['inbound_context_fingerprint'] ?? null,
            ]);

            $replyGuard->rememberReplyIdentity($conversation, $replyIdentity);

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
            replyIdentity       : $replyIdentity,
            bookingDecision     : $bookingDecision,
        );

        $replyGuard->rememberReplyIdentity($conversation, $replyIdentity);

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
     * @param  array<string, mixed>  $replyIdentity
     * @param  array<string, mixed>|null  $bookingDecision
     */
    private function persistOutboundReply(
        Conversation $conversation,
        ConversationManagerService $conversationManager,
        array $intentResult,
        array $finalReply,
        array $replyIdentity,
        ?array $bookingDecision,
    ): ConversationMessage {
        $rawPayload = array_filter([
            'source' => $finalReply['meta']['source'] ?? 'ai_generated',
            'reply_action' => $finalReply['meta']['action'] ?? null,
            'is_fallback' => $finalReply['is_fallback'],
            'intent' => $intentResult['intent'],
            'booking_action' => $bookingDecision['action'] ?? null,
            'outbound_fingerprint' => $replyIdentity['outbound_fingerprint'] ?? null,
            'response_hash' => $replyIdentity['response_hash'] ?? null,
            'state_response_hash' => $replyIdentity['state_response_hash'] ?? null,
            'reply_state' => $replyIdentity['booking_state'] ?? null,
            'reply_expected_input' => $replyIdentity['expected_input'] ?? null,
            'inbound_context_fingerprint' => $replyIdentity['inbound_context_fingerprint'] ?? null,
            'review_hash' => $finalReply['meta']['review_hash'] ?? null,
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
     * @param  array{text?: string, is_fallback?: bool, meta?: array<string, mixed>}  $finalReply
     */
    private function shouldSkipDuplicateFinalReview(Conversation $conversation, array $finalReply): bool
    {
        if (($finalReply['meta']['action'] ?? null) !== 'ask_confirmation') {
            return false;
        }

        $reviewHash = trim((string) ($finalReply['meta']['review_hash'] ?? ''));

        if ($reviewHash === '') {
            return false;
        }

        return $conversation->messages()
            ->outbound()
            ->latest('id')
            ->limit(20)
            ->get(['raw_payload'])
            ->contains(function (ConversationMessage $message) use ($reviewHash): bool {
                return is_array($message->raw_payload)
                    && (string) ($message->raw_payload['review_hash'] ?? '') === $reviewHash;
            });
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
                'text' => $m->message_text,
                'sent_at' => $m->sent_at?->toDateTimeString(),
            ])
            ->values()
            ->all();
    }

    private function notifyInboundDuringTakeover(
        ConversationMessage $message,
        Conversation $conversation,
        Customer $customer,
    ): void {
        try {
            $customerName = $customer->name ?? $customer->phone_e164 ?? 'Pelanggan';

            AdminNotification::create([
                'type' => 'inbound_during_takeover',
                'title' => "Pesan baru saat takeover: {$customerName}",
                'body' => implode("\n", [
                    'Pesan masuk saat admin takeover aktif (bot tidak membalas).',
                    'Percakapan : #'.$conversation->id,
                    'Customer   : '.$customerName.' ('.($customer->phone_e164 ?? '-').')',
                    'Pesan      : '.$message->textPreview(200),
                ]),
                'payload' => [
                    'conversation_id' => $conversation->id,
                    'message_id' => $message->id,
                    'handoff_admin_id' => $conversation->handoff_admin_id,
                    'customer_id' => $customer->id,
                ],
                'is_read' => false,
            ]);
        } catch (\Throwable $e) {
            WaLog::error('[Job:ProcessIncoming] failed to create inbound-during-takeover notification', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function logLearningTurnSafely(
        LearningSignalLoggerService $learningSignalLogger,
        LearningSignalPayload $payload,
    ): void {
        if (! config('chatbot.continuous_improvement.enabled', true)) {
            return;
        }

        try {
            $learningSignalLogger->logTurn($payload);
        } catch (\Throwable $e) {
            WaLog::warning('[Job:ProcessIncoming] learning signal logging failed (non-fatal)', [
                'conversation_id' => $payload->conversationId,
                'message_id' => $payload->inboundMessageId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>|null  $bookingDecision
     * @param  array<string, mixed>  $finalReply
     * @param  array<string, mixed>  $policyGuardResult
     */
    private function deriveLearningAction(
        ?array $bookingDecision,
        array $finalReply,
        array $policyGuardResult = [],
    ): ?string {
        $action = $bookingDecision['action'] ?? $finalReply['meta']['action'] ?? $policyGuardResult['meta']['action'] ?? null;

        return is_string($action) && trim($action) !== '' ? trim($action) : null;
    }

    /**
     * @param  array<string, mixed>  $intentResult
     * @param  array<string, mixed>  $finalReply
     * @param  array<string, mixed>|null  $bookingDecision
     */
    private function didHandoffHappen(
        Conversation $conversation,
        array $intentResult,
        array $finalReply,
        ?array $bookingDecision = null,
    ): bool {
        if ($conversation->needs_human || $conversation->isAdminTakeover()) {
            return true;
        }

        if (($intentResult['handoff_recommended'] ?? false) === true) {
            return true;
        }

        if (($intentResult['intent'] ?? null) === IntentType::HumanHandoff->value) {
            return true;
        }

        $action = (string) ($finalReply['meta']['action'] ?? $bookingDecision['action'] ?? '');

        return str_contains($action, 'handoff') || str_contains($action, 'takeover');
    }

    private function emergencyFallbackText(): string
    {
        return 'Maaf, sistem kami sedang mengalami gangguan sementara. Tim kami akan segera merespons pesan Anda.';
    }

    private function saveEmergencyFallback(
        Conversation $conversation,
        ConversationManagerService $conversationManager,
        ConversationOutboundRouterService $outboundRouter,
    ): ?ConversationMessage {
        try {
            $outbound = $conversationManager->appendOutboundMessage(
                conversation : $conversation,
                text         : $this->emergencyFallbackText(),
                rawPayload   : ['source' => 'emergency_fallback'],
            );

            // Still attempt to deliver the fallback message through the active channel.
            $outboundRouter->dispatch($outbound, WaLog::traceId());

            return $outbound;
        } catch (\Throwable $inner) {
            WaLog::error('[Job:ProcessIncoming] emergency fallback also failed', [
                'error' => $inner->getMessage(),
                'file' => $inner->getFile().':'.$inner->getLine(),
            ]);
        }

        return null;
    }
}
