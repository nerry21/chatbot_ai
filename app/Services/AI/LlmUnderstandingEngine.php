<?php

namespace App\Services\AI;

use App\Data\AI\LlmUnderstandingResult;
use App\Data\Chatbot\ConversationContextPayload;
use App\Enums\IntentType;

class LlmUnderstandingEngine
{
    public function __construct(
        private readonly LlmClientService $llmClient,
        private readonly UnderstandingPromptBuilderService $promptBuilder,
        private readonly UnderstandingOutputParserService $parser,
    ) {
    }

    /**
     * @param  array<int, array<string, mixed>>  $recentHistory
     * @param  array<string, mixed>  $conversationState
     * @param  array<string, mixed>  $knownEntities
     * @param  array<int, string|IntentType>  $allowedIntents
     */
    public function understand(
        string $latestMessage,
        array $recentHistory = [],
        array $conversationState = [],
        array $knownEntities = [],
        array $allowedIntents = [],
        ?string $conversationSummary = null,
        array $crmContext = [],
        bool $adminTakeover = false,
        ?int $conversationId = null,
        ?int $messageId = null,
    ): LlmUnderstandingResult {
        $message = trim($latestMessage);
        $normalizedAllowedIntents = $this->normalizeAllowedIntents($allowedIntents);

        if ($message === '') {
            return LlmUnderstandingResult::fallback(
                intent: $this->fallbackIntent($normalizedAllowedIntents),
                reasoningSummary: 'Pesan kosong sehingga understanding memakai fallback aman.',
            );
        }

        $prompts = $this->promptBuilder->build(
            latestMessage: $message,
            recentHistory: $recentHistory,
            conversationState: $conversationState,
            knownEntities: $knownEntities,
            allowedIntents: $normalizedAllowedIntents,
            conversationSummary: $conversationSummary,
            crmContext: $crmContext,
            adminTakeover: $adminTakeover,
        );

        $raw = $this->llmClient->understandMessage([
            'conversation_id' => $conversationId,
            'message_id' => $messageId,
            'message_text' => $message,
            'recent_history' => $recentHistory,
            'conversation_state' => $conversationState,
            'known_entities' => $knownEntities,
            'conversation_summary' => $conversationSummary,
            'crm_context' => $crmContext,
            'admin_takeover' => $adminTakeover,
            'allowed_intents' => $normalizedAllowedIntents,
            'system' => $prompts['system'],
            'user' => $prompts['user'],
            'model' => config('chatbot.llm.models.understanding', config('chatbot.llm.models.intent')),
        ]);

        return $this->parser->parse($raw, $normalizedAllowedIntents);
    }

    /**
     * @param  array<int, string|IntentType>  $allowedIntents
     */
    public function understandFromContext(
        ConversationContextPayload $contextPayload,
        array $allowedIntents = [],
    ): LlmUnderstandingResult {
        $input = $contextPayload->toUnderstandingInput();

        return $this->understand(
            latestMessage: $contextPayload->latestMessageText,
            recentHistory: $input['recent_history'],
            conversationState: $input['conversation_state'],
            knownEntities: $input['known_entities'],
            allowedIntents: $allowedIntents,
            conversationSummary: $input['conversation_summary'] ?? null,
            crmContext: is_array($input['crm_context'] ?? null) ? $input['crm_context'] : [],
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
}
