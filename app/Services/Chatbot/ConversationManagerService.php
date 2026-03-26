<?php

namespace App\Services\Chatbot;

use App\Enums\ConversationStatus;
use App\Enums\MessageDeliveryStatus;
use App\Enums\MessageDirection;
use App\Enums\SenderType;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Customer;
use Carbon\Carbon;

class ConversationManagerService
{
    /**
     * Inactivity threshold in hours before a new conversation is started.
     */
    private const INACTIVITY_HOURS = 24;

    /**
     * State key used to indicate who the conversation is waiting for.
     */
    private const STATE_WAITING_FOR = 'waiting_for';

    public function __construct(
        private readonly ConversationStateService $stateService,
    ) {}

    // -------------------------------------------------------------------------
    // Conversation lifecycle
    // -------------------------------------------------------------------------

    public function findOrCreateActive(Customer $customer, string $channel = 'whatsapp'): Conversation
    {
        if (! $this->shouldStartNewConversation($customer, $channel)) {
            $existing = Conversation::query()
                ->forCustomer($customer->id)
                ->onChannel($channel)
                ->active()
                ->latest('last_message_at')
                ->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        return $this->createConversation($customer, $channel);
    }

    public function shouldStartNewConversation(Customer $customer, string $channel = 'whatsapp'): bool
    {
        $latest = Conversation::query()
            ->forCustomer($customer->id)
            ->onChannel($channel)
            ->latest('last_message_at')
            ->first();

        if ($latest === null) {
            return true;
        }

        if ($latest->isTerminal()) {
            return true;
        }

        if (
            $latest->last_message_at !== null
            && $latest->last_message_at->lt(now()->subHours(self::INACTIVITY_HOURS))
        ) {
            return true;
        }

        return false;
    }

    public function close(Conversation $conversation): void
    {
        $conversation->status = ConversationStatus::Closed;
        $conversation->save();
    }

    public function escalate(Conversation $conversation, ?string $reason = null): void
    {
        $conversation->status            = ConversationStatus::Escalated;
        $conversation->needs_human       = true;
        $conversation->escalation_reason = $reason;
        $conversation->save();

        $this->stateService->forget($conversation, self::STATE_WAITING_FOR);
    }

    public function markWaitingUser(Conversation $conversation): void
    {
        $this->stateService->put($conversation, self::STATE_WAITING_FOR, 'user');
    }

    public function markWaitingAdmin(Conversation $conversation, ?string $reason = null): void
    {
        $conversation->needs_human = true;
        $conversation->save();

        $this->stateService->put($conversation, self::STATE_WAITING_FOR, 'admin');

        if ($reason !== null) {
            $this->stateService->put($conversation, 'waiting_reason', $reason);
        }
    }

    // -------------------------------------------------------------------------
    // Message appending
    // -------------------------------------------------------------------------

    /**
     * Record an inbound (customer → bot) message on an existing conversation.
     *
     * @param  array<string, mixed>  $payload
     */
    public function appendInboundMessage(Conversation $conversation, array $payload): ConversationMessage
    {
        $message = ConversationMessage::create([
            'conversation_id' => $conversation->id,
            'direction'       => MessageDirection::Inbound,
            'sender_type'     => SenderType::Customer,
            'message_type'    => $payload['message_type'] ?? 'text',
            'message_text'    => $payload['message_text'] ?? null,
            'raw_payload'     => $payload['raw_payload'] ?? [],
            'wa_message_id'   => $payload['wa_message_id'] ?? null,
            'is_fallback'     => false,
            'sent_at'         => $payload['sent_at'] ?? now(),
        ]);

        $conversation->touchLastMessage();

        return $message;
    }

    /**
     * Record an outbound bot reply on a conversation.
     *
     * @param  array<string, mixed>  $rawPayload
     */
    public function appendOutboundMessage(
        Conversation $conversation,
        string $text,
        array $rawPayload = [],
    ): ConversationMessage {
        $message = ConversationMessage::create([
            'conversation_id' => $conversation->id,
            'direction'       => MessageDirection::Outbound,
            'sender_type'     => SenderType::Bot,
            'message_type'    => 'text',
            'message_text'    => $text,
            'raw_payload'     => $rawPayload,
            'is_fallback'     => false,
            'sent_at'         => now(),
            'delivery_status' => MessageDeliveryStatus::Pending,
        ]);

        $conversation->touchLastMessage();

        return $message;
    }

    /**
     * Record an outbound admin (human agent) reply on a conversation.
     * Uses SenderType::Agent so it is visually distinct in the thread and
     * the SendWhatsAppMessageJob will pick it up correctly.
     *
     * @param  array<string, mixed>  $rawPayload
     */
    public function appendAdminOutboundMessage(
        Conversation $conversation,
        string $text,
        int $adminId,
        array $rawPayload = [],
    ): ConversationMessage {
        $message = ConversationMessage::create([
            'conversation_id' => $conversation->id,
            'direction'       => MessageDirection::Outbound,
            'sender_type'     => SenderType::Agent,
            'message_type'    => 'text',
            'message_text'    => $text,
            'raw_payload'     => array_merge($rawPayload, ['admin_id' => $adminId]),
            'is_fallback'     => false,
            'sent_at'         => now(),
            'delivery_status' => MessageDeliveryStatus::Pending,
        ]);

        $conversation->touchLastMessage();

        return $message;
    }

    // -------------------------------------------------------------------------
    // State delegation (backwards-compatible wrappers)
    // -------------------------------------------------------------------------

    public function getState(Conversation $conversation, string $key, mixed $default = null): mixed
    {
        return $this->stateService->get($conversation, $key, $default);
    }

    public function setState(
        Conversation $conversation,
        string $key,
        mixed $value,
        ?Carbon $expiresAt = null,
    ): void {
        $this->stateService->put($conversation, $key, $value, $expiresAt);
    }

    // -------------------------------------------------------------------------
    // Private
    // -------------------------------------------------------------------------

    private function createConversation(Customer $customer, string $channel): Conversation
    {
        $now = now();

        return Conversation::create([
            'customer_id'     => $customer->id,
            'channel'         => $channel,
            'status'          => ConversationStatus::Active,
            'handoff_mode'    => 'bot',
            'started_at'      => $now,
            'last_message_at' => $now,
        ]);
    }
}
