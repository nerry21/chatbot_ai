<?php

namespace App\Data\Chatbot;

final readonly class ConversationContextPayload
{
    /**
     * @param  array<int, ConversationContextMessage>  $recentMessages
     * @param  array<string, mixed>  $conversationState
     * @param  array<string, mixed>  $knownEntities
     * @param  array<string, mixed>  $resolvedContext
     * @param  array<string, mixed>  $customerMemory
     * @param  array<string, mixed>  $crmContext
     * @param  array<string, mixed>  $crmHints
     */
    public function __construct(
        public int $conversationId,
        public int $messageId,
        public string $latestMessageText,
        public array $recentMessages,
        public array $conversationState,
        public array $knownEntities,
        public array $resolvedContext,
        public ?string $conversationSummary,
        public array $customerMemory,
        public array $crmContext,
        public bool $adminTakeover,
        public array $crmHints = [],
        public ?string $jobTraceId = null,
    ) {}

    /**
     * Penting:
     * Tahap understanding awal TIDAK menerima full crm_context.
     * Input understanding harus tetap ramping:
     * - latest message
     * - recent history
     * - conversation state ringkas
     * - known entities ringkas
     * - summary ringkas
     * - crm_hints yang sudah dibatasi
     * - admin flag
     *
     * Tidak boleh memasukkan customer_memory penuh, resolved_context mentah besar,
     * atau crm_context penuh ke tahap ini.
     *
     * @return array{
     *     conversation_id: int,
     *     message_id: int,
     *     trace_id: string|null,
     *     latest_message: string,
     *     recent_history: array<int, array{role: string, text: string, sent_at: string|null}>,
     *     conversation_state: array<string, mixed>,
     *     known_entities: array<string, mixed>,
     *     conversation_summary: string|null,
     *     crm_hints: array<string, mixed>,
     *     admin_takeover: bool
     * }
     */
    public function toUnderstandingInput(): array
    {
        return [
            'conversation_id' => $this->conversationId,
            'message_id' => $this->messageId,
            'trace_id' => $this->jobTraceId,
            'latest_message' => $this->latestMessageText,
            'recent_history' => $this->recentHistoryForUnderstanding(),
            'conversation_state' => $this->conversationStateForUnderstanding(),
            'known_entities' => $this->knownEntitiesForUnderstanding(),
            'conversation_summary' => $this->conversationSummaryForUnderstanding(),
            'crm_hints' => $this->crmHintsForUnderstanding(),
            'admin_takeover' => $this->adminTakeover,
        ];
    }

    /**
     * Full AI context untuk tahap setelah understanding.
     *
     * @return array<string, mixed>
     */
    public function toAiContext(): array
    {
        return [
            'conversation_id' => $this->conversationId,
            'message_id' => $this->messageId,
            'job_trace_id' => $this->jobTraceId,
            'trace_id' => $this->jobTraceId,

            'message_text' => $this->latestMessageText,
            'admin_takeover' => $this->adminTakeover,

            // full context for post-understanding stages
            'customer_memory' => $this->customerMemory,
            'crm_context' => $this->crmContext,
            'crm_hints' => $this->crmHints,

            'active_states' => $this->conversationState,
            'known_entities' => $this->knownEntities,
            'resolved_context' => $this->resolvedContext,
            'conversation_summary' => $this->conversationSummary,

            // legacy and structured history variants
            'recent_messages' => $this->recentMessagesForLegacyAiContext(),
            'context_messages' => $this->recentHistoryForUnderstanding(),

            // audit helpers
            'understanding_input' => $this->toUnderstandingInput(),
            'context_contract' => [
                'understanding_uses_full_crm' => false,
                'full_context_available_post_understanding' => true,
            ],
            'context_boundary_audit' => $this->contextBoundaryAudit(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'conversation_id' => $this->conversationId,
            'message_id' => $this->messageId,
            'job_trace_id' => $this->jobTraceId,
            'trace_id' => $this->jobTraceId,
            'latest_message_text' => $this->latestMessageText,
            'recent_messages' => array_map(
                static fn (ConversationContextMessage $message): array => $message->toArray(),
                $this->recentMessages,
            ),
            'conversation_state' => $this->conversationState,
            'known_entities' => $this->knownEntities,
            'resolved_context' => $this->resolvedContext,
            'conversation_summary' => $this->conversationSummary,
            'customer_memory' => $this->customerMemory,
            'crm_context' => $this->crmContext,
            'crm_hints' => $this->crmHints,
            'admin_takeover' => $this->adminTakeover,
            'understanding_input' => $this->toUnderstandingInput(),
            'context_boundary_audit' => $this->contextBoundaryAudit(),
        ];
    }

    /**
     * @param  array<string, mixed>  $crmContext
     */
    public function withCrmContext(array $crmContext): self
    {
        return new self(
            conversationId: $this->conversationId,
            messageId: $this->messageId,
            latestMessageText: $this->latestMessageText,
            recentMessages: $this->recentMessages,
            conversationState: $this->conversationState,
            knownEntities: $this->knownEntities,
            resolvedContext: $this->resolvedContext,
            conversationSummary: $this->conversationSummary,
            customerMemory: $this->customerMemory,
            crmContext: $crmContext,
            adminTakeover: $this->adminTakeover,
            crmHints: $this->crmHints,
            jobTraceId: $this->jobTraceId,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function crmHintsForUnderstanding(): array
    {
        return array_filter(
            $this->crmHints,
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
     * @return array<int, array{role: string, text: string, sent_at: string|null}>
     */
    private function recentHistoryForUnderstanding(): array
    {
        return array_map(
            static fn (ConversationContextMessage $message): array => $message->toUnderstandingArray(),
            $this->recentMessages,
        );
    }

    /**
     * Penting:
     * Jangan bocorkan full crm_context ke conversation_state understanding.
     *
     * @return array<string, mixed>
     */
    private function conversationStateForUnderstanding(): array
    {
        return array_filter([
            ...$this->conversationState,
            'resolved_context_keys' => $this->resolvedContextKeysForUnderstanding(),
            'conversation_summary_present' => $this->conversationSummary !== null && trim($this->conversationSummary) !== '',
            'admin_takeover' => $this->adminTakeover,
        ], static fn (mixed $value): bool => match (true) {
            is_bool($value) => true,
            is_array($value) => $value !== [],
            default => $value !== null && $value !== '',
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function knownEntitiesForUnderstanding(): array
    {
        return array_filter(
            $this->knownEntities,
            static fn (mixed $value): bool => match (true) {
                is_string($value) => trim($value) !== '',
                is_array($value) => $value !== [],
                default => $value !== null,
            },
        );
    }

    /**
     * @return array<int, string>
     */
    private function resolvedContextKeysForUnderstanding(): array
    {
        return array_values(array_filter(
            array_keys($this->resolvedContext),
            static fn (mixed $key): bool => is_string($key) && trim($key) !== '',
        ));
    }

    private function conversationSummaryForUnderstanding(int $limit = 220): ?string
    {
        if ($this->conversationSummary === null) {
            return null;
        }

        $summary = trim($this->conversationSummary);

        if ($summary === '') {
            return null;
        }

        return mb_substr($summary, 0, $limit);
    }

    /**
     * @return array<string, mixed>
     */
    public function contextBoundaryAudit(): array
    {
        return [
            'conversation_id' => $this->conversationId,
            'message_id' => $this->messageId,
            'job_trace_id' => $this->jobTraceId,
            'understanding_uses_full_crm' => false,
            'understanding_crm_hint_keys' => array_keys($this->crmHintsForUnderstanding()),
            'understanding_state_keys' => array_keys($this->conversationStateForUnderstanding()),
            'understanding_known_entity_keys' => array_keys($this->knownEntitiesForUnderstanding()),
            'resolved_context_keys_for_understanding' => $this->resolvedContextKeysForUnderstanding(),
        ];
    }

    /**
     * @return array<int, array{direction: string, text: string, sent_at: string|null}>
     */
    private function recentMessagesForLegacyAiContext(): array
    {
        return array_map(
            static fn (ConversationContextMessage $message): array => $message->toLegacyArray(),
            $this->recentMessages,
        );
    }
}
