<?php

namespace App\Services\Chatbot;

use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Customer;
use App\Models\Escalation;
use App\Models\LeadPipeline;
use Illuminate\Support\Str;

class CustomerMemoryService
{
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
        $customer->loadMissing('tags', 'crmContact');

        $latestLead = LeadPipeline::query()
            ->where('customer_id', $customer->id)
            ->latest()
            ->first();

        $latestConversation = Conversation::query()
            ->where('customer_id', $customer->id)
            ->latest('last_message_at')
            ->first();

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
                'latest_lead_stage' => $latestLead?->stage,
                'lead_owner_admin_id' => $latestLead?->owner_admin_id,
                'lead_notes' => $latestLead?->notes,
                'last_escalation_status' => $latestEscalation?->status,
                'last_escalation_reason' => $latestEscalation?->reason,
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
