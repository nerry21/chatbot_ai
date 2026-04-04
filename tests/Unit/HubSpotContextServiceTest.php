<?php

namespace Tests\Unit;

use App\Models\CrmContact;
use App\Models\Customer;
use App\Services\CRM\HubSpotContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HubSpotContextServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Cache::flush();

        parent::tearDown();
    }

    public function test_it_uses_local_crm_payload_when_remote_refresh_is_disabled(): void
    {
        config([
            'chatbot.crm.ai_context.enabled' => true,
            'chatbot.crm.ai_context.ttl_seconds' => 600,
            'chatbot.crm.hubspot.enabled' => false,
            'chatbot.crm.hubspot.token' => '',
        ]);

        $customer = Customer::create([
            'name' => 'Nerry',
            'phone_e164' => '+6281234567890',
            'status' => 'active',
        ]);

        CrmContact::create([
            'customer_id' => $customer->id,
            'provider' => 'hubspot',
            'external_contact_id' => 'local-123',
            'sync_status' => 'synced',
            'sync_payload' => [
                'id' => 'local-123',
                'properties' => [
                    'company' => 'Local Travel',
                    'jobtitle' => 'Manager',
                    'lifecyclestage' => 'customer',
                    'hs_lead_status' => 'OPEN',
                    'hubspotscore' => '42',
                ],
            ],
        ]);

        $context = app(HubSpotContextService::class)->resolveForCustomer($customer->fresh());

        $this->assertSame('Local Travel', $context['company']);
        $this->assertSame('Manager', $context['jobtitle']);
        $this->assertSame('customer', $context['lifecycle_stage']);
        $this->assertSame('OPEN', $context['lead_status']);
        $this->assertSame('42', $context['score']);
        $this->assertSame('crm_sync_payload', $context['source']);
    }

    public function test_it_refreshes_context_from_hubspot_api_when_enabled(): void
    {
        config([
            'chatbot.crm.ai_context.enabled' => true,
            'chatbot.crm.ai_context.ttl_seconds' => 600,
            'chatbot.crm.hubspot.enabled' => true,
            'chatbot.crm.hubspot.token' => 'test-token',
        ]);

        $customer = Customer::create([
            'name' => 'Nerry',
            'phone_e164' => '+6281234567890',
            'status' => 'active',
        ]);

        $crmContact = CrmContact::create([
            'customer_id' => $customer->id,
            'provider' => 'hubspot',
            'external_contact_id' => 'remote-123',
            'sync_status' => 'synced',
            'sync_payload' => [
                'id' => 'remote-123',
                'properties' => [
                    'company' => 'Old Company',
                ],
            ],
        ]);

        Http::fake([
            'https://api.hubapi.com/crm/v3/objects/contacts/remote-123*' => Http::response([
                'id' => 'remote-123',
                'properties' => [
                    'company' => 'Fresh Company',
                    'jobtitle' => 'Director',
                    'lifecyclestage' => 'opportunity',
                    'hs_lead_status' => 'QUALIFIED',
                    'hubspotscore' => '88',
                ],
            ]),
        ]);

        $context = app(HubSpotContextService::class)->resolveForCustomer($customer->fresh());

        $this->assertSame('Fresh Company', $context['company']);
        $this->assertSame('Director', $context['jobtitle']);
        $this->assertSame('opportunity', $context['lifecycle_stage']);
        $this->assertSame('QUALIFIED', $context['lead_status']);
        $this->assertSame('88', $context['score']);
        $this->assertSame('hubspot_api', $context['source']);

        $crmContact->refresh();

        $this->assertSame('Fresh Company', $crmContact->sync_payload['properties']['company']);
        $this->assertNotNull($crmContact->last_synced_at);
    }
}
