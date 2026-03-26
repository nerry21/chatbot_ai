<?php

namespace App\Services\AI;

use App\Models\Conversation;
use App\Services\Support\JsonSchemaValidatorService;
use Illuminate\Support\Facades\Log;

class ConversationSummaryService
{
    /**
     * Minimum number of inbound messages required before summarizing.
     * Avoids wasting tokens on very short or just-started conversations.
     */
    private const MIN_MESSAGES_TO_SUMMARIZE = 3;

    public function __construct(
        private readonly LlmClientService          $llmClient,
        private readonly PromptBuilderService      $promptBuilder,
        private readonly JsonSchemaValidatorService $validator,
    ) {}

    /**
     * Generate a short summary of the conversation.
     *
     * Returns ['summary' => ''] when the conversation has too few messages
     * or when summarization fails — the caller should treat this as a no-op.
     *
     * @param  array<string, mixed>  $context  Must contain: recent_messages, conversation_id.
     *                                          Optional: customer_memory, message_id.
     * @return array{summary: string}
     */
    public function summarize(Conversation $conversation, array $context): array
    {
        $recentMessages = $context['recent_messages'] ?? [];

        // Skip if not enough conversation history
        $inboundCount = count(array_filter(
            $recentMessages,
            fn ($m) => ($m['direction'] ?? '') === 'inbound',
        ));

        if ($inboundCount < self::MIN_MESSAGES_TO_SUMMARIZE) {
            return ['summary' => ''];
        }

        try {
            // 1. Build prompts
            $prompts = $this->promptBuilder->buildSummaryPrompt($context);

            // 2. Assemble LLM context
            $llmContext = array_merge($context, [
                'system'          => $prompts['system'],
                'user'            => $prompts['user'],
                'model'           => config('chatbot.llm.models.summary'),
                'conversation_id' => $conversation->id,
            ]);

            // 3. Call LLM
            $raw = $this->llmClient->summarizeConversation($llmContext);

            // 4. Validate
            $validated = $this->validator->validateAndFill(
                data         : $raw,
                requiredKeys : ['summary'],
                defaults     : ['summary' => ''],
            );

            if ($validated === null) {
                Log::warning('ConversationSummaryService: missing summary key in LLM output', ['raw' => $raw]);
                return ['summary' => ''];
            }

            $summary = trim((string) ($validated['summary'] ?? ''));

            return ['summary' => $summary];
        } catch (\Throwable $e) {
            Log::error('ConversationSummaryService: unexpected error', ['error' => $e->getMessage()]);
            return ['summary' => ''];
        }
    }
}
