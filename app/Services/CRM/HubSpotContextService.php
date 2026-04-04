<?php

namespace App\Services\CRM;

use App\Models\Customer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class HubSpotContextService
{
    public function __construct(
        private readonly HubSpotService $hubSpotService,
    ) {}

    /**
     * Resolve CRM context that is safe and useful for AI prompts.
     *
     * Priority:
     * 1. crm_contacts.sync_payload
     * 2. refresh from HubSpot API when enabled and external ID exists
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

        $cacheKey = 'hubspot_ai_context_customer_'.$customer->id;
        $ttl = (int) config('chatbot.crm.ai_context.ttl_seconds', 600);

        return Cache::remember($cacheKey, $ttl, function () use ($crmContact): array {
            $basePayload = is_array($crmContact->sync_payload) ? $crmContact->sync_payload : [];
            $context = $this->hubSpotService->extractAiContextFromPayload($basePayload);

            if (
                $this->hubSpotService->isEnabled()
                && filled($crmContact->external_contact_id)
            ) {
                try {
                    $fresh = $this->hubSpotService->getContactById((string) $crmContact->external_contact_id);

                    if (($fresh['status'] ?? null) === 'success' && is_array($fresh['data'] ?? null)) {
                        $context = $this->hubSpotService->extractAiContextFromPayload($fresh['data']);

                        $crmContact->update([
                            'sync_payload' => $fresh['data'],
                            'last_synced_at' => now(),
                        ]);

                        $context['source'] = 'hubspot_api';

                        return $this->normalizeForPrompt($context);
                    }
                } catch (\Throwable $e) {
                    Log::warning('[HubSpotContext] refresh failed, fallback to local payload', [
                        'crm_contact_id' => $crmContact->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            if ($context !== []) {
                $context['source'] = 'crm_sync_payload';
            }

            return $this->normalizeForPrompt($context);
        });
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function normalizeForPrompt(array $context): array
    {
        return array_filter([
            'contact_id' => $context['contact_id'] ?? null,
            'firstname' => $context['firstname'] ?? null,
            'lastname' => $context['lastname'] ?? null,
            'company' => $context['company'] ?? null,
            'jobtitle' => $context['jobtitle'] ?? null,
            'lifecycle_stage' => $context['lifecyclestage'] ?? null,
            'lead_status' => $context['hs_lead_status'] ?? null,
            'score' => $context['hubspotscore'] ?? null,
            'created_at' => $context['createdate'] ?? null,
            'updated_at' => $context['lastmodifieddate'] ?? null,
            'source' => $context['source'] ?? null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }
}
