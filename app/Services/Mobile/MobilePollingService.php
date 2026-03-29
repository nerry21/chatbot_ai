<?php

namespace App\Services\Mobile;

use App\Models\Conversation;
use App\Models\Customer;

class MobilePollingService
{
    public function __construct(
        private readonly MobileConversationService $conversationService,
        private readonly MobileMessageService $messageService,
    ) {}

    /**
     * @return array{conversation: Conversation, messages: \Illuminate\Database\Eloquent\Collection<int, \App\Models\ConversationMessage>, latest_message_id: int|null, unread_count: int, delta_count: int}
     */
    public function poll(Customer $customer, Conversation $conversation, ?int $afterMessageId = null): array
    {
        $conversation = $this->conversationService->detail($customer, $conversation);
        $messages = $this->messageService->list($customer, $conversation, $afterMessageId);
        $freshConversation = $this->conversationService->detail($customer, $conversation->fresh() ?? $conversation);

        return [
            'conversation' => $freshConversation,
            'messages' => $messages,
            'latest_message_id' => $freshConversation->messages()->max('id'),
            'unread_count' => $this->conversationService->unreadCount($freshConversation),
            'delta_count' => $messages->count(),
        ];
    }
}
