<?php

namespace App\Services\Chatbot;

use App\Enums\MessageDirection;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Customer;
use App\Services\CRM\HubSpotContextService;

class CustomerMemoryService
{
    public function __construct(
        private readonly HubSpotContextService $hubSpotContextService,
    ) {}

    /**
     * Build a structured memory snapshot for a customer.
     *
     * This snapshot is designed to be injected into an LLM prompt context
     * in a later stage. It contains no AI-generated content - all data
     * is sourced directly from the database.
     *
     * @return array<string, mixed>
     */
    public function buildMemory(Customer $customer): array
    {
        $customer->loadMissing('aliases', 'crmContact');

        $latestConversation = $this->resolveLatestConversation($customer);
        $hubspotContext = $this->hubSpotContextService->resolveForCustomer($customer);

        return [
            'customer_id' => $customer->id,
            'primary_name' => $customer->name,
            'aliases' => $customer->aliases->pluck('alias_name')->values()->all(),
            'phone_e164' => $customer->phone_e164,
            'email' => $customer->email,
            'total_bookings' => $customer->total_bookings,
            'last_interaction_at' => $customer->last_interaction_at?->toIso8601String(),
            'preferred_pickup' => $customer->preferred_pickup,
            'preferred_destination' => $customer->preferred_destination,
            'preferred_departure_time' => $customer->preferred_departure_time?->toIso8601String(),
            'last_conversation_summary' => $latestConversation?->summary,
            'last_inbound_message' => $this->lastMessageText($latestConversation, MessageDirection::Inbound),
            'last_outbound_message' => $this->lastMessageText($latestConversation, MessageDirection::Outbound),
            'hubspot' => $hubspotContext,
        ];
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Resolve the most recent conversation for a customer, with its messages
     * pre-loaded. Avoids re-querying if the customer already has conversations
     * loaded in memory.
     */
    private function resolveLatestConversation(Customer $customer): ?Conversation
    {
        return Conversation::query()
            ->where('customer_id', $customer->id)
            ->latest('last_message_at')
            ->with([
                'messages' => function ($q): void {
                    $q->orderByDesc('sent_at')->limit(20);
                },
            ])
            ->first();
    }

    /**
     * Extract the text of the most recent message in a given direction.
     */
    private function lastMessageText(?Conversation $conversation, MessageDirection $direction): ?string
    {
        if ($conversation === null) {
            return null;
        }

        /** @var ConversationMessage|null $message */
        $message = $conversation->messages
            ->firstWhere('direction', $direction);

        return $message?->message_text;
    }
}
