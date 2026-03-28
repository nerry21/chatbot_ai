<?php

namespace App\Data\AI;

use App\Enums\GroundedResponseMode;

final readonly class GroundedResponseFacts
{
    /**
     * @param  array<string, mixed>  $intentResult
     * @param  array<string, mixed>  $entityResult
     * @param  array<string, mixed>  $resolvedContext
     * @param  array<string, mixed>  $officialFacts
     */
    public function __construct(
        public int $conversationId,
        public int $messageId,
        public GroundedResponseMode $mode,
        public string $latestMessageText,
        public ?string $customerName,
        public array $intentResult,
        public array $entityResult,
        public array $resolvedContext,
        public ?string $conversationSummary,
        public bool $adminTakeover,
        public array $officialFacts,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'conversation_id' => $this->conversationId,
            'message_id' => $this->messageId,
            'mode' => $this->mode->value,
            'latest_message_text' => $this->latestMessageText,
            'customer_name' => $this->customerName,
            'intent_result' => $this->intentResult,
            'entity_result' => $this->entityResult,
            'resolved_context' => $this->resolvedContext,
            'conversation_summary' => $this->conversationSummary,
            'admin_takeover' => $this->adminTakeover,
            'official_facts' => $this->officialFacts,
        ];
    }
}
