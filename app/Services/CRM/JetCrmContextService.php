<?php

namespace App\Services\CRM;

use App\Models\Customer;
use Illuminate\Support\Facades\Cache;

class JetCrmContextService
{
    /**
     * Resolve CRM context that is safe and useful for AI prompts.
     * Reads from the local Customer + crm_contacts.sync_payload only —
     * JET Travel does not subscribe to HubSpot, so there is no remote refresh.
     *
     * @return array<string, mixed>
     */
    public function resolveForCustomer(Customer $customer): array
    {
        if (! config('chatbot.crm.ai_context.enabled', true)) {
            return [];
        }

        $customer->loadMissing('crmContact');

        $crmContact = $customer->crmContact;

        if ($crmContact === null) {
            return [];
        }

        $cacheKey = 'jet_crm_ai_context_customer_'.$customer->id;
        $ttl = (int) config('chatbot.crm.ai_context.ttl_seconds', 600);

        return Cache::remember($cacheKey, $ttl, function () use ($customer, $crmContact): array {
            $payload = is_array($crmContact->sync_payload) ? $crmContact->sync_payload : [];
            $contextSource = $payload !== [] ? 'crm_sync_payload' : null;

            return $this->clean($this->buildPromptSafeContext(
                customer: $customer,
                crmContact: $crmContact,
                payload: $payload,
                contextSource: $contextSource,
            ));
        });
    }

    /**
     * Compatibility alias for older callers.
     *
     * @return array<string, mixed>
     */
    public function getContext(Customer $customer): array
    {
        return $this->resolveForCustomer($customer);
    }

    /**
     * Resolve the customer profile (preferences + counters) used by the LLM
     * agent to drive warmth-aware replies. Cached per customer.
     *
     * @return array<string, mixed>
     */
    public function resolveCustomerProfile(Customer $customer): array
    {
        if (! config('chatbot.crm.ai_context.enabled', true)) {
            return [];
        }

        $cacheKey = 'jet_crm_profile_customer_'.$customer->id;
        $ttl = (int) config('chatbot.crm.ai_context.ttl_seconds', 600);

        return Cache::remember($cacheKey, $ttl, function () use ($customer): array {
            $customer->loadMissing('preferences');

            $totalBookings = (int) ($customer->total_bookings ?? 0);
            $isReturning = $totalBookings >= 1;

            $prefs = [];
            foreach ($customer->preferences as $pref) {
                if ((float) $pref->confidence < 0.5) {
                    continue;
                }
                $prefs[$pref->key] = [
                    'value'      => $pref->getTypedValue(),
                    'confidence' => (float) $pref->confidence,
                ];
            }

            return [
                'name'                     => $customer->name,
                'phone'                    => $customer->phone_e164,
                'is_returning_customer'    => $isReturning,
                'total_bookings'           => $totalBookings,
                'last_interaction_at'      => optional($customer->last_interaction_at)->toIso8601String(),
                'preferred_pickup'         => $customer->preferred_pickup,
                'preferred_destination'    => $customer->preferred_destination,
                'preferred_departure_time' => optional($customer->preferred_departure_time)->format('H:i'),
                'preferences'              => $prefs,
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function buildPromptSafeContext(
        Customer $customer,
        mixed $crmContact,
        array $payload,
        ?string $contextSource = null,
    ): array {
        $properties = is_array($crmContact->hubspot_properties ?? null)
            ? $crmContact->hubspot_properties
            : [];

        if ($properties === []) {
            $properties = is_array($payload['properties'] ?? null)
                ? $payload['properties']
                : [];
        }

        return [
            'contact_id' => $crmContact->hubspot_contact_id
                ?? $crmContact->external_contact_id
                ?? ($payload['id'] ?? null),
            'email' => $properties['email'] ?? $customer->email,
            'firstname' => $properties['firstname'] ?? null,
            'lastname' => $properties['lastname'] ?? null,
            'phone' => $properties['phone'] ?? $customer->phone_e164,
            'company' => $properties['company'] ?? null,
            'lifecycle_stage' => $properties['lifecyclestage'] ?? null,
            'lead_status' => $properties['hs_lead_status'] ?? null,
            'job_title' => $properties['jobtitle'] ?? null,
            'jobtitle' => $properties['jobtitle'] ?? null,
            'city' => $properties['city'] ?? null,
            'state' => $properties['state'] ?? null,
            'country' => $properties['country'] ?? null,
            'lastmodifieddate' => $properties['lastmodifieddate'] ?? null,
            'source' => $properties['hs_analytics_source'] ?? $contextSource,
            'sync_source' => $contextSource,
            'owner_id' => $properties['hubspot_owner_id'] ?? null,
            'score' => $properties['hubspotscore'] ?? null,
            'created_at' => $properties['createdate'] ?? null,
            'updated_at' => $properties['lastmodifieddate'] ?? null,

            'ai_memory' => $this->clean([
                'last_ai_intent' => $properties['last_ai_intent'] ?? null,
                'last_ai_summary' => $properties['last_ai_summary'] ?? null,
                'customer_interest_topic' => $properties['customer_interest_topic'] ?? null,
                'ai_sentiment' => $properties['ai_sentiment'] ?? null,
                'needs_human_followup' => $this->normalizeBool($properties['needs_human_followup'] ?? null),
                'last_whatsapp_interaction_at' => $properties['last_whatsapp_interaction_at'] ?? null,
                'admin_takeover_active' => $this->normalizeBool($properties['admin_takeover_active'] ?? null),
            ]),
        ];
    }

    private function normalizeBool(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(trim((string) $value));

        return match ($normalized) {
            '1', 'true', 'yes', 'y' => true,
            '0', 'false', 'no', 'n' => false,
            default => null,
        };
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
