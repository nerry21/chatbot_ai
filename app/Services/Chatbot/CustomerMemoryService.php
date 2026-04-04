<?php

namespace App\Services\Chatbot;

use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Customer;
use App\Models\Escalation;
use App\Models\LeadPipeline;
use App\Services\CRM\CRMContextService;
use Illuminate\Support\Str;

class CustomerMemoryService
{
    public function __construct(
        private readonly CRMContextService $crmContextService,
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
        $customer->loadMissing('aliases', 'tags', 'crmContact');

        $latestLead = LeadPipeline::query()
            ->where('customer_id', $customer->id)
            ->latest()
            ->first();

        $latestConversation = Conversation::query()
            ->where('customer_id', $customer->id)
            ->latest('last_message_at')
            ->first();

        $crmContext = $this->crmContextService->build(
            customer: $customer,
            conversation: $latestConversation,
            booking: null,
        );

        $latestEscalation = Escalation::query()
            ->whereHas('conversation', function ($q) use ($customer): void {
                $q->where('customer_id', $customer->id);
            })
            ->latest()
            ->first();

        $recentInboundTopics = ConversationMessage::query()
            ->whereHas('conversation', function ($q) use ($customer): void {
                $q->where('customer_id', $customer->id);
            })
            ->where('direction', 'inbound')
            ->whereNotNull('message_text')
            ->latest('created_at')
            ->limit(12)
            ->pluck('message_text')
            ->filter()
            ->map(fn ($text) => Str::limit(trim((string) $text), 140))
            ->values()
            ->all();

        $recentTags = $customer->tags()
            ->latest('id')
            ->limit(20)
            ->pluck('tag')
            ->filter()
            ->values()
            ->all();

        return $this->clean([
            'customer_id' => $customer->id,
            'primary_name' => $customer->name,
            'aliases' => $customer->aliases->pluck('alias_name')->filter()->values()->all(),
            'phone_e164' => $customer->phone_e164,
            'email' => $customer->email,
            'total_bookings' => (int) ($customer->total_bookings ?? 0),
            'last_interaction_at' => $customer->last_interaction_at?->toIso8601String(),
            'preferred_pickup' => $customer->preferred_pickup,
            'preferred_destination' => $customer->preferred_destination,
            'preferred_departure_time' => $customer->preferred_departure_time?->toIso8601String(),
            'last_conversation_summary' => $latestConversation?->summary,
            'last_inbound_message' => $this->lastMessageText($latestConversation, 'inbound'),
            'last_outbound_message' => $this->lastMessageText($latestConversation, 'outbound'),
            'crm_context' => $crmContext,
            'hubspot' => is_array($crmContext['hubspot'] ?? null) ? $crmContext['hubspot'] : [],
            'customer_profile' => [
                'customer_id' => $customer->id,
                'name' => $customer->name,
                'phone_e164' => $customer->phone_e164,
                'email' => $customer->email,
                'total_bookings' => (int) ($customer->total_bookings ?? 0),
                'last_interaction_at' => $customer->last_interaction_at?->toIso8601String(),
            ],

            'relationship_memory' => [
                'is_new_customer' => (int) ($customer->total_bookings ?? 0) === 0,
                'is_returning_customer' => (int) ($customer->total_bookings ?? 0) > 0,
                'preferred_pickup' => $customer->preferred_pickup,
                'preferred_destination' => $customer->preferred_destination,
                'recent_tags' => $recentTags,
            ],

            'conversation_memory' => [
                'latest_conversation_id' => $latestConversation?->id,
                'latest_conversation_summary' => $latestConversation?->summary,
                'latest_intent' => $latestConversation?->current_intent,
                'needs_human' => $latestConversation?->needs_human,
                'handoff_mode' => $latestConversation?->handoff_mode,
                'bot_paused' => $latestConversation?->bot_paused,
                'recent_customer_topics' => $recentInboundTopics,
            ],

            'crm_memory' => [
                'crm_contact_id' => $customer->crmContact?->external_contact_id,
                'latest_lead_stage' => $crmContext['lead_pipeline']['stage'] ?? $latestLead?->stage,
                'lead_owner_admin_id' => $crmContext['lead_pipeline']['owner_admin_id'] ?? $latestLead?->owner_admin_id,
                'lead_notes' => $crmContext['lead_pipeline']['notes'] ?? $latestLead?->notes,
                'last_escalation_status' => $crmContext['escalation']['status'] ?? $latestEscalation?->status,
                'last_escalation_reason' => $crmContext['escalation']['reason'] ?? $latestEscalation?->reason,
            ],

            'business_memory' => [
                'known_preferences' => array_values(array_filter([
                    $customer->preferred_pickup ? 'pickup:'.$customer->preferred_pickup : null,
                    $customer->preferred_destination ? 'destination:'.$customer->preferred_destination : null,
                ])),
                'followup_recommended' => (bool) ($latestConversation?->needs_human ?? false)
                    || in_array($latestEscalation?->status, ['open', 'assigned'], true),
            ],
        ]);
    }

    private function lastMessageText(?Conversation $conversation, string $direction): ?string
    {
        if ($conversation === null) {
            return null;
        }

        $message = $conversation->messages()
            ->where('direction', $direction)
            ->whereNotNull('message_text')
            ->latest('id')
            ->first();

        $text = trim((string) ($message?->message_text ?? ''));

        return $text !== '' ? $text : null;
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
