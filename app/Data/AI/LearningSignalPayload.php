<?php

namespace App\Data\AI;

final readonly class LearningSignalPayload
{
    /**
     * @param  array<string, mixed>  $contextSnapshot
     * @param  array<string, mixed>  $understandingResult
     * @param  array<string, mixed>|null  $groundedFacts
     * @param  array<string, mixed>  $finalResponseMeta
     * @param  array<string, mixed>  $classifierContext
     */
    public function __construct(
        public int $conversationId,
        public int $inboundMessageId,
        public string $userMessage,
        public ?string $contextSummary,
        public array $contextSnapshot,
        public array $understandingResult,
        public ?string $chosenAction,
        public ?array $groundedFacts,
        public string $finalResponse,
        public array $finalResponseMeta,
        public bool $fallbackUsed,
        public bool $handoffHappened,
        public bool $adminTakeoverActive,
        public bool $outboundSent,
        public ?int $outboundMessageId,
        public array $classifierContext = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'conversation_id' => $this->conversationId,
            'inbound_message_id' => $this->inboundMessageId,
            'user_message' => $this->userMessage,
            'context_summary' => $this->contextSummary,
            'context_snapshot' => $this->contextSnapshot,
            'understanding_result' => $this->understandingResult,
            'chosen_action' => $this->chosenAction,
            'grounded_facts' => $this->groundedFacts,
            'final_response' => $this->finalResponse,
            'final_response_meta' => $this->finalResponseMeta,
            'fallback_used' => $this->fallbackUsed,
            'handoff_happened' => $this->handoffHappened,
            'admin_takeover_active' => $this->adminTakeoverActive,
            'outbound_sent' => $this->outboundSent,
            'outbound_message_id' => $this->outboundMessageId,
            'classifier_context' => $this->classifierContext,
        ];
    }
}
