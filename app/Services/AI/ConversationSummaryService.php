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
        private readonly LlmClientService $llmClient,
        private readonly PromptBuilderService $promptBuilder,
        private readonly JsonSchemaValidatorService $validator,
    ) {}

    /**
     * Generate a business summary payload for the conversation.
     *
     * Returns ['summary' => ''] when the conversation has too few messages
     * or when summarization fails; the caller should treat this as a no-op.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function summarize(Conversation $conversation, array $context): array
    {
        $recentMessages = $context['recent_messages'] ?? [];

        $inboundCount = count(array_filter(
            $recentMessages,
            fn ($message) => ($message['direction'] ?? '') === 'inbound',
        ));

        if ($inboundCount < self::MIN_MESSAGES_TO_SUMMARIZE) {
            return ['summary' => ''];
        }

        try {
            $summaryInput = array_merge($context, [
                'conversation' => $this->buildBusinessSummaryPayload($conversation),
                'crm_context' => is_array($context['crm_context'] ?? null) ? $context['crm_context'] : [],
                'customer_memory' => is_array($context['customer_memory'] ?? null) ? $context['customer_memory'] : [],
            ]);

            $prompts = $this->promptBuilder->buildSummaryPrompt($summaryInput);

            $llmContext = array_merge($summaryInput, [
                'system' => $prompts['system'],
                'user' => $prompts['user'],
                'model' => config('chatbot.llm.models.summary'),
                'conversation_id' => $conversation->id,
            ]);

            $raw = $this->llmClient->summarizeConversation($llmContext);

            $validated = $this->validator->validateAndFill(
                data: $raw,
                requiredKeys: ['summary'],
                defaults: [
                    'summary' => '',
                    'intent' => '',
                    'sentiment' => '',
                    'next_action' => '',
                ],
            );

            if ($validated === null) {
                Log::warning('ConversationSummaryService: missing summary key in LLM output', ['raw' => $raw]);

                return ['summary' => ''];
            }

            $summaryResult = [
                'summary' => trim((string) ($validated['summary'] ?? '')),
                'intent' => trim((string) ($validated['intent'] ?? '')),
                'sentiment' => trim((string) ($validated['sentiment'] ?? '')),
                'next_action' => trim((string) ($validated['next_action'] ?? '')),
            ];

            return $this->mergeBusinessSummary(
                summaryResult: $summaryResult,
                crmContext: is_array($summaryInput['crm_context'] ?? null) ? $summaryInput['crm_context'] : [],
                customerMemory: is_array($summaryInput['customer_memory'] ?? null) ? $summaryInput['customer_memory'] : [],
            );
        } catch (\Throwable $e) {
            Log::error('ConversationSummaryService: unexpected error', ['error' => $e->getMessage()]);

            return ['summary' => ''];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function buildBusinessSummaryPayload(Conversation $conversation): array
    {
        $conversation->loadMissing('customer');

        $recentMessages = $conversation->messages()
            ->latest('created_at')
            ->limit(12)
            ->get()
            ->reverse()
            ->values();

        $messageLines = $recentMessages->map(function ($message): string {
            $role = $message->direction?->value === 'inbound' ? 'customer' : 'assistant';
            $text = trim((string) ($message->message_text ?? ''));

            if ($text === '') {
                $text = '[media/non-text]';
            }

            return $role.': '.$text;
        })->all();

        return $this->clean([
            'conversation_id' => $conversation->id,
            'customer_id' => $conversation->customer?->id,
            'current_intent' => $conversation->current_intent,
            'needs_human' => $conversation->needs_human,
            'handoff_mode' => $conversation->handoff_mode,
            'bot_paused' => $conversation->bot_paused,
            'last_message_at' => $conversation->last_message_at?->toIso8601String(),
            'recent_transcript' => $messageLines,
            'existing_summary' => $conversation->summary,
        ]);
    }

    /**
     * @param  array<string, mixed>  $summaryResult
     * @param  array<string, mixed>  $crmContext
     * @param  array<string, mixed>  $customerMemory
     * @return array<string, mixed>
     */
    public function mergeBusinessSummary(
        array $summaryResult,
        array $crmContext = [],
        array $customerMemory = [],
    ): array {
        $summaryText = trim((string) ($summaryResult['summary'] ?? ''));
        $nextAction = trim((string) ($summaryResult['next_action'] ?? ''));
        $sentiment = trim((string) ($summaryResult['sentiment'] ?? ''));
        $intent = trim((string) ($summaryResult['intent'] ?? ''));

        $crmConversation = is_array($crmContext['conversation'] ?? null)
            ? $crmContext['conversation']
            : [];

        $crmLead = is_array($crmContext['lead_pipeline'] ?? null)
            ? $crmContext['lead_pipeline']
            : [];

        $crmFlags = is_array($crmContext['business_flags'] ?? null)
            ? $crmContext['business_flags']
            : [];

        $memoryRelationship = is_array($customerMemory['relationship_memory'] ?? null)
            ? $customerMemory['relationship_memory']
            : [];

        return $this->clean([
            'summary' => $summaryText,
            'intent' => $intent !== '' ? $intent : ($crmConversation['current_intent'] ?? null),
            'sentiment' => $sentiment !== '' ? $sentiment : null,
            'next_action' => $nextAction !== '' ? $nextAction : null,
            'lead_stage' => $crmLead['stage'] ?? null,
            'needs_human_followup' => $crmConversation['needs_human'] ?? $crmFlags['needs_human_followup'] ?? null,
            'customer_type' => ($memoryRelationship['is_returning_customer'] ?? false) ? 'returning' : 'new',
            'preferred_pickup' => $memoryRelationship['preferred_pickup'] ?? null,
            'preferred_destination' => $memoryRelationship['preferred_destination'] ?? null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function clean(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->clean($value);

                if ($payload[$key] === []) {
                    unset($payload[$key]);
                }

                continue;
            }

            if ($value === null) {
                unset($payload[$key]);
                continue;
            }

            if (is_string($value) && trim($value) === '') {
                unset($payload[$key]);
            }
        }

        return $payload;
    }
}
