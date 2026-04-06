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
     * @return array<string, mixed>
     */
    public function runtimeAuditSnapshot(): array
    {
        return [
            'trace_id' => $this->lastRuntimeMeta['trace_id'] ?? null,
            'provider' => $this->lastRuntimeMeta['provider'] ?? null,
            'model' => $this->lastRuntimeMeta['model'] ?? null,
            'status' => $this->lastRuntimeMeta['status'] ?? null,
            'degraded_mode' => $this->lastRuntimeMeta['degraded_mode'] ?? null,
            'schema_valid' => $this->lastRuntimeMeta['schema_valid'] ?? null,
            'fallback_reason' => $this->lastRuntimeMeta['fallback_reason'] ?? null,
            'conversation_id' => $this->lastRuntimeMeta['conversation_id'] ?? null,
            'message_id' => $this->lastRuntimeMeta['message_id'] ?? null,
            'input_contract' => $this->lastRuntimeMeta['input_contract'] ?? [],
        ];
    }

    private function normalizeOptionalText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        return $normalized !== '' ? $normalized : null;
    }

    /**
     * @param  array<string, mixed>  $crmHints
     * @return array<string, mixed>
     */
    private function sanitizeCrmHints(array $crmHints): array
    {
        return array_filter(
            $crmHints,
            static function (mixed $value, string|int $key): bool {
                if ($key === '_meta') {
                    return is_array($value) && $value !== [];
                }

                return match (true) {
                    is_bool($value) => $value === true,
                    is_string($value) => trim($value) !== '',
                    is_int($value), is_float($value) => true,
                    is_array($value) => $value !== [],
                    default => $value !== null,
                };
            },
            ARRAY_FILTER_USE_BOTH,
        );
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
            $traceId = $this->buildTraceId(
                conversationId: $conversationId,
                messageId: $messageId,
            );

            $this->lastRuntimeMeta = [
                'trace_id' => $traceId,
                'provider' => 'openai',
                'task_key' => 'understanding',
                'task_type' => 'message_understanding',
                'understanding_mode' => 'llm_first_with_crm_hints_only',
                'status' => 'fallback',
                'degraded_mode' => true,
                'fallback_reason' => 'empty_message',
                'schema_valid' => false,
                'cache_hit' => false,
                'model' => config(
                    'openai.tasks.understanding.model',
                    config('chatbot.llm.models.understanding', config('chatbot.llm.models.intent'))
                ),
                'conversation_id' => $conversationId,
                'message_id' => $messageId,
                'allowed_intents' => $normalizedAllowedIntents,
                'prompt_built' => false,
                'client_called' => false,
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

        $conversationSummary = $this->normalizeOptionalText($conversationSummary);
        $crmHints = $this->sanitizeCrmHints($crmHints);

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

        $baseRuntimeMeta = [
            'trace_id' => $traceId,
            'provider' => 'openai',
            'task_key' => 'understanding',
            'task_type' => 'message_understanding',
            'understanding_mode' => 'llm_first_with_crm_hints_only',
            'conversation_id' => $conversationId,
            'message_id' => $messageId,
            'model' => $understandingModel,
            'allowed_intents' => $normalizedAllowedIntents,
            'admin_takeover' => $adminTakeover,
            'input_contract' => [
                'uses_full_crm_context' => false,
                'crm_hint_keys' => array_keys($crmHints),
                'conversation_state_keys' => array_keys($conversationState),
                'known_entity_keys' => array_keys($knownEntities),
                'recent_history_count' => count($recentHistory),
                'has_conversation_summary' => $conversationSummary !== null && trim($conversationSummary) !== '',
            ],
            'prompt_built' => true,
            'client_called' => false,
        ];

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

        $this->lastRuntimeMeta = array_merge(
            $baseRuntimeMeta,
            $llmRuntimeMeta,
            [
                'trace_id' => $traceId,
                'provider' => $llmRuntimeMeta['provider'] ?? $baseRuntimeMeta['provider'],
                'task_key' => $llmRuntimeMeta['task_key'] ?? $baseRuntimeMeta['task_key'],
                'task_type' => $llmRuntimeMeta['task_type'] ?? $baseRuntimeMeta['task_type'],
                'understanding_mode' => $llmRuntimeMeta['understanding_mode'] ?? $baseRuntimeMeta['understanding_mode'],
                'conversation_id' => $conversationId,
                'message_id' => $messageId,
                'model' => $llmRuntimeMeta['model'] ?? $understandingModel,
                'allowed_intents' => $normalizedAllowedIntents,
                'client_called' => true,
            ],
        );

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
                'llm_runtime' => $this->lastRuntimeMeta,
                'input_contract' => $baseRuntimeMeta['input_contract'],
                'allowed_intents' => $normalizedAllowedIntents,
                'admin_takeover' => $adminTakeover,
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

        if (! isset($crmHints['_meta']) || ! is_array($crmHints['_meta'])) {
            $crmHints['_meta'] = [];
        }

        $crmHints['_meta'] = array_merge($crmHints['_meta'], [
            'source' => 'ConversationContextPayload::toUnderstandingInput',
            'reduced_by' => 'UnderstandingCrmHintReducerService',
            'uses_full_crm_context' => false,
            'job_trace_id' => $contextPayload->jobTraceId,
        ]);

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
            'understanding',
            $conversationId !== null ? 'c'.$conversationId : null,
            $messageId !== null ? 'm'.$messageId : null,
            now()->format('YmdHis'),
            substr(md5((string) microtime(true)), 0, 8),
        ]);

        return implode('-', $parts);
    }
}
