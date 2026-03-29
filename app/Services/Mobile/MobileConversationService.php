<?php

namespace App\Services\Mobile;

use App\Enums\ConversationChannel;
use App\Enums\MessageDirection;
use App\Enums\SenderType;
use App\Models\Conversation;
use App\Models\Customer;
use App\Services\Chatbot\ConversationManagerService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class MobileConversationService
{
    public function __construct(
        private readonly ConversationManagerService $conversationManager,
    ) {}

    public function start(Customer $customer, array $payload = []): Conversation
    {
        return DB::transaction(function () use ($customer, $payload): Conversation {
            $customer->forceFill([
                'preferred_channel' => ConversationChannel::MobileLiveChat->value,
                'last_interaction_at' => now(),
            ])->save();

            $conversation = $this->conversationManager->findOrCreateActive(
                customer: $customer,
                channel: ConversationChannel::MobileLiveChat->value,
                attributes: [
                    'source_app' => $payload['source_app'] ?? config('chatbot.mobile_live_chat.default_source_app', 'flutter'),
                    'is_from_mobile_app' => true,
                ],
            );

            if (! filled($conversation->channel_conversation_id)) {
                $conversation->forceFill([
                    'channel_conversation_id' => $this->buildChannelConversationId($customer, $conversation),
                ])->save();
            }

            return $this->detail($customer, $conversation->fresh() ?? $conversation);
        });
    }

    /**
     * @return Collection<int, Conversation>
     */
    public function list(Customer $customer): Collection
    {
        return Conversation::query()
            ->with(['customer', 'assignedAdmin'])
            ->where('customer_id', $customer->id)
            ->where('channel', ConversationChannel::MobileLiveChat->value)
            ->withCount([
                'messages as unread_messages_count' => function ($query): void {
                    $query->where('direction', MessageDirection::Outbound->value)
                        ->where('sender_type', '!=', SenderType::System->value)
                        ->whereNull('read_at');
                },
            ])
            ->latest('last_message_at')
            ->latest('id')
            ->limit((int) config('chatbot.mobile_live_chat.max_conversations', 20))
            ->get();
    }

    public function detail(Customer $customer, Conversation $conversation): Conversation
    {
        $conversation = $this->ensureOwnedConversation($customer, $conversation);

        $conversation->load([
            'customer',
            'assignedAdmin',
            'handoffAdmin',
        ]);

        $conversation->setAttribute('mobile_unread_count', $this->unreadCount($conversation));

        return $conversation;
    }

    public function ensureOwnedConversation(Customer $customer, Conversation $conversation): Conversation
    {
        $ownedConversation = Conversation::query()
            ->whereKey($conversation->id)
            ->where('customer_id', $customer->id)
            ->where('channel', ConversationChannel::MobileLiveChat->value)
            ->first();

        if ($ownedConversation === null) {
            throw new HttpException(404, 'Percakapan mobile tidak ditemukan.');
        }

        return $ownedConversation;
    }

    public function touchCustomerRead(Conversation $conversation): void
    {
        $conversation->forceFill([
            'last_read_at_customer' => now(),
        ])->save();
    }

    public function touchAdminRead(Conversation $conversation): void
    {
        $conversation->forceFill([
            'last_read_at_admin' => now(),
        ])->save();
    }

    public function unreadCount(Conversation $conversation): int
    {
        return $conversation->messages()
            ->where('direction', MessageDirection::Outbound->value)
            ->where('sender_type', '!=', SenderType::System->value)
            ->whereNull('read_at')
            ->count();
    }

    private function buildChannelConversationId(Customer $customer, Conversation $conversation): string
    {
        $seed = $customer->mobile_user_id ?: 'customer-'.$customer->id;

        return Str::limit('mobile-live-chat:'.$seed.':'.$conversation->id, 120, '');
    }
}
