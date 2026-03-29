<?php

namespace App\Services\Chatbot;

use App\Enums\ConversationChannel;
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

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function findOrCreateActive(
        Customer $customer,
        string $channel = 'whatsapp',
        array $attributes = [],
    ): Conversation
    {
        if (! $this->shouldStartNewConversation($customer, $channel)) {
            $existing = Conversation::query()
                ->forCustomer($customer->id)
                ->onChannel($channel)
                ->active()
                ->latest('last_message_at')
                ->first();

            if ($existing !== null) {
                if ($attributes !== []) {
                    $existing->fill(array_filter($attributes, static fn (mixed $value): bool => $value !== null));
                    if ($existing->isDirty()) {
                        $existing->save();
                    }
                }

                return $existing;
            }
        }

        return $this->createConversation($customer, $channel, $attributes);
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
            'client_message_id' => $payload['client_message_id'] ?? null,
            'channel_message_id' => $payload['channel_message_id'] ?? $payload['wa_message_id'] ?? null,
            'sender_user_id' => $payload['sender_user_id'] ?? null,
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
        string $messageType = 'text',
    ): ConversationMessage {
        $message = ConversationMessage::create([
            'conversation_id' => $conversation->id,
            'direction'       => MessageDirection::Outbound,
            'sender_type'     => SenderType::Bot,
            'message_type'    => $messageType,
            'message_text'    => $text,
            'raw_payload'     => $rawPayload,
            'client_message_id' => $rawPayload['client_message_id'] ?? null,
            'channel_message_id' => $rawPayload['channel_message_id'] ?? null,
            'sender_user_id' => $rawPayload['sender_user_id'] ?? null,
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
        string $messageType = 'text',
        array $rawPayload = [],
    ): ConversationMessage {
        $message = ConversationMessage::create([
            'conversation_id' => $conversation->id,
            'direction'       => MessageDirection::Outbound,
            'sender_type'     => SenderType::Admin,
            'message_type'    => $messageType,
            'message_text'    => $text,
            'raw_payload'     => array_merge($rawPayload, ['admin_id' => $adminId]),
            'sender_user_id'  => $adminId,
            'client_message_id' => $rawPayload['client_message_id'] ?? null,
            'channel_message_id' => $rawPayload['channel_message_id'] ?? null,
            'is_fallback'     => false,
            'sent_at'         => now(),
            'delivery_status' => MessageDeliveryStatus::Pending,
        ]);

        $conversation->touchLastMessage();

        return $message;
    }

    /**
     * Record an internal system event message on the conversation thread.
     * This message is not dispatched to WhatsApp and only exists for audit/UI context.
     *
     * @param  array<string, mixed>  $rawPayload
     */
    public function appendSystemMessage(
        Conversation $conversation,
        string $text,
        array $rawPayload = [],
    ): ConversationMessage {
        $message = ConversationMessage::create([
            'conversation_id' => $conversation->id,
            'direction' => MessageDirection::Outbound,
            'sender_type' => SenderType::System,
            'message_type' => 'text',
            'message_text' => $text,
            'raw_payload' => $rawPayload,
            'is_fallback' => false,
            'sent_at' => now(),
            'delivery_status' => MessageDeliveryStatus::Skipped,
            'delivery_error' => 'system_event',
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

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createConversation(Customer $customer, string $channel, array $attributes = []): Conversation
    {
        $now = now();

        return Conversation::create(array_merge([
            'customer_id'     => $customer->id,
            'channel'         => $channel,
            'status'          => ConversationStatus::Active,
            'handoff_mode'    => 'bot',
            'started_at'      => $now,
            'last_message_at' => $now,
            'source_app'      => $attributes['source_app'] ?? null,
            'is_from_mobile_app' => $channel === ConversationChannel::MobileLiveChat->value,
        ], array_filter($attributes, static fn (mixed $value): bool => $value !== null)));
    }
}
