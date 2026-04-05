<?php

namespace App\Services\AI;

use App\Data\AI\LlmUnderstandingResult;
use App\Data\Chatbot\ConversationContextPayload;
use App\Enums\IntentType;

class LlmUnderstandingEngine
{
    /**
     * @var array<string, mixed>
     */
    private array $lastRuntimeMeta = [];

    public function __construct(
        private readonly LlmClientService $llmClient,
        private readonly UnderstandingPromptBuilderService $promptBuilder,
        private readonly UnderstandingOutputParserService $parser,
        private readonly UnderstandingCrmHintReducerService $crmHintReducer,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function lastRuntimeMeta(): array
    {
        return $this->lastRuntimeMeta;
    }

    /**
     * @param  array<int, array<string, mixed>>  $recentHistory
     * @param  array<string, mixed>  $conversationState
     * @param  array<string, mixed>  $knownEntities
     * @param  array<int, string|IntentType>  $allowedIntents
     * @param  array<string, mixed>  $crmHints
     */
    public function understand(
        string $latestMessage,
        array $recentHistory = [],
        array $conversationState = [],
        array $knownEntities = [],
        array $allowedIntents = [],
        ?string $conversationSummary = null,
        array $crmHints = [],
        bool $adminTakeover = false,
        ?int $conversationId = null,
        ?int $messageId = null,
    ): LlmUnderstandingResult {
        $message = trim($latestMessage);
        $normalizedAllowedIntents = $this->normalizeAllowedIntents($allowedIntents);
        $this->lastRuntimeMeta = [];

        if ($message === '') {
            $this->lastRuntimeMeta = [
                'provider' => 'openai',
                'task_key' => 'understanding',
                'task_type' => 'message_understanding',
                'status' => 'fallback',
                'degraded_mode' => true,
                'fallback_reason' => 'empty_message',
                'schema_valid' => false,
                'cache_hit' => false,
            ];

            return LlmUnderstandingResult::fallback(
                intent: $this->fallbackIntent($normalizedAllowedIntents),
                reasoningSummary: 'Pesan kosong sehingga understanding memakai fallback aman.',
            );
        }

        $traceId = $this->buildTraceId(
            conversationId: $conversationId,
            messageId: $messageId,
        );

        $prompts = $this->promptBuilder->build(
            latestMessage: $message,
            recentHistory: $recentHistory,
            conversationState: $conversationState,
            knownEntities: $knownEntities,
            allowedIntents: $normalizedAllowedIntents,
            conversationSummary: $conversationSummary,
            crmHints: $crmHints,
            adminTakeover: $adminTakeover,
            traceId: $traceId,
        );

        $understandingModel = config(
            'openai.tasks.understanding.model',
            config('chatbot.llm.models.understanding', config('chatbot.llm.models.intent'))
        );

        $raw = $this->llmClient->understandMessage([
            'conversation_id' => $conversationId,
            'message_id' => $messageId,
            'message_text' => $message,
            'recent_history' => $recentHistory,
            'conversation_state' => $conversationState,
            'known_entities' => $knownEntities,
            'conversation_summary' => $conversationSummary,
            'crm_hints' => $crmHints,
            'admin_takeover' => $adminTakeover,
            'allowed_intents' => $normalizedAllowedIntents,
            'understanding_mode' => 'llm_first_with_crm_hints_only',
            'system' => $prompts['system'],
            'user' => $prompts['user'],
            'model' => $understandingModel,
            'expect_json' => true,
        ]);

        $llmRuntimeMeta = is_array($raw['_llm'] ?? null) ? $raw['_llm'] : [];
        $this->lastRuntimeMeta = $llmRuntimeMeta;

        return $this->parser->parse(
            payload: $raw,
            allowedIntents: $normalizedAllowedIntents,
            metadata: [
                'trace_id' => $traceId,
                'conversation_id' => $conversationId,
                'message_id' => $messageId,
                'understanding_mode' => 'llm_first_with_crm_hints_only',
                'model' => $understandingModel,
                'task_key' => 'understanding',
                'task_type' => 'message_understanding',
                'llm_runtime' => $llmRuntimeMeta,
            ],
        );
    }

    /**
     * @param  array<int, string|IntentType>  $allowedIntents
     */
    public function understandFromContext(
        ConversationContextPayload $contextPayload,
        array $allowedIntents = [],
    ): LlmUnderstandingResult {
        $input = $contextPayload->toUnderstandingInput();

        $crmHints = $this->crmHintReducer->reduce(
            crmContext: $contextPayload->crmContext,
            conversationSummary: $contextPayload->conversationSummary,
            adminTakeover: $contextPayload->adminTakeover,
        );

        return $this->understand(
            latestMessage: $contextPayload->latestMessageText,
            recentHistory: $input['recent_history'],
            conversationState: $input['conversation_state'],
            knownEntities: $input['known_entities'],
            allowedIntents: $allowedIntents,
            conversationSummary: $input['conversation_summary'] ?? null,
            crmHints: $crmHints,
            adminTakeover: (bool) ($input['admin_takeover'] ?? false),
            conversationId: $contextPayload->conversationId,
            messageId: $contextPayload->messageId,
        );
    }

    /**
     * @param  array<int, string|IntentType>  $allowedIntents
     * @return array<int, string>
     */
    private function normalizeAllowedIntents(array $allowedIntents): array
    {
        if ($allowedIntents === []) {
            return array_map(
                static fn (IntentType $intent): string => $intent->value,
                IntentType::cases(),
            );
        }

        $normalized = [];

        foreach ($allowedIntents as $intent) {
            $value = $intent instanceof IntentType ? $intent->value : strtolower(trim((string) $intent));

            if ($value !== '' && ! in_array($value, $normalized, true)) {
                $normalized[] = $value;
            }
        }

        return $normalized;
    }

    /**
     * @param  array<int, string>  $allowedIntents
     */
    private function fallbackIntent(array $allowedIntents): string
    {
        if ($allowedIntents === []) {
            return IntentType::Unknown->value;
        }

        if (in_array(IntentType::Unknown->value, $allowedIntents, true)) {
            return IntentType::Unknown->value;
        }

        return $allowedIntents[0];
    }

    private function buildTraceId(?int $conversationId = null, ?int $messageId = null): string
    {
        $parts = array_filter([
            'trace',
            $conversationId !== null ? 'c'.$conversationId : null,
            $messageId !== null ? 'm'.$messageId : null,
            now()->format('YmdHis'),
            substr(md5((string) microtime(true)), 0, 8),
        ]);

        return implode('-', $parts);
    }
}
