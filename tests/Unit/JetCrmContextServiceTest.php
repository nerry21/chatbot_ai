<?php

namespace Tests\Unit;

use App\Models\CrmContact;
use App\Models\Customer;
use App\Models\CustomerPreference;
use App\Services\CRM\JetCrmContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class JetCrmContextServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Cache::flush();

        parent::tearDown();
    }

    public function test_it_returns_empty_array_when_ai_context_is_disabled(): void
    {
        config(['chatbot.crm.ai_context.enabled' => false]);

        $customer = Customer::create([
            'name' => 'Nerry',
            'phone_e164' => '+6281234567890',
            'status' => 'active',
        ]);

        $context = app(JetCrmContextService::class)->resolveForCustomer($customer);

        $this->assertSame([], $context);
    }

    public function test_it_returns_empty_array_when_customer_has_no_crm_contact(): void
    {
        config([
            'chatbot.crm.ai_context.enabled' => true,
            'chatbot.crm.ai_context.ttl_seconds' => 600,
        ]);

        $customer = Customer::create([
            'name' => 'Nerry',
            'phone_e164' => '+6281234567890',
            'status' => 'active',
        ]);

        $context = app(JetCrmContextService::class)->resolveForCustomer($customer->fresh());

        $this->assertSame([], $context);
    }

    public function test_it_resolves_context_from_local_crm_sync_payload(): void
    {
        config([
            'chatbot.crm.ai_context.enabled' => true,
            'chatbot.crm.ai_context.ttl_seconds' => 600,
        ]);

        $customer = Customer::create([
            'name' => 'Nerry',
            'phone_e164' => '+6281234567890',
            'email' => 'nerry@example.com',
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
                    'last_ai_intent' => 'booking',
                    'needs_human_followup' => 'true',
                ],
            ],
        ]);

        $context = app(JetCrmContextService::class)->resolveForCustomer($customer->fresh());

        $this->assertSame('Local Travel', $context['company']);
        $this->assertSame('Manager', $context['jobtitle']);
        $this->assertSame('customer', $context['lifecycle_stage']);
        $this->assertSame('OPEN', $context['lead_status']);
        $this->assertSame('42', $context['score']);
        $this->assertSame('crm_sync_payload', $context['source']);
        $this->assertSame('booking', $context['ai_memory']['last_ai_intent'] ?? null);
        $this->assertTrue((bool) ($context['ai_memory']['needs_human_followup'] ?? false));
    }

    public function test_it_falls_back_to_customer_fields_when_payload_is_missing_them(): void
    {
        config([
            'chatbot.crm.ai_context.enabled' => true,
            'chatbot.crm.ai_context.ttl_seconds' => 600,
        ]);

        $customer = Customer::create([
            'name' => 'Nerry',
            'phone_e164' => '+6281234567890',
            'email' => 'nerry@example.com',
            'status' => 'active',
        ]);

        CrmContact::create([
            'customer_id' => $customer->id,
            'provider' => 'hubspot',
            'external_contact_id' => 'local-456',
            'sync_status' => 'local_only',
            'sync_payload' => [],
        ]);

        $context = app(JetCrmContextService::class)->resolveForCustomer($customer->fresh());

        $this->assertSame('+6281234567890', $context['phone']);
        $this->assertSame('nerry@example.com', $context['email']);
        $this->assertSame('local-456', $context['contact_id']);
    }

    public function test_resolve_customer_profile_returns_returning_status_with_reliable_prefs(): void
    {
        config(['chatbot.crm.ai_context.enabled' => true]);

        $customer = Customer::create([
            'name'           => 'Pak Budi',
            'phone_e164'     => '+6281234567890',
            'status'         => 'active',
            'total_bookings' => 6,
            'preferred_pickup' => 'Pasir Pengaraian',
            'preferred_destination' => 'Pekanbaru',
            'preferred_departure_time' => '2026-04-29 07:00:00',
        ]);

        CustomerPreference::create([
            'customer_id' => $customer->id,
            'key'         => 'customer_tier',
            'value'       => 'silver',
            'value_type'  => 'string',
            'confidence'  => 1.0,
            'source'      => 'inferred',
        ]);

        // Below threshold — must be excluded.
        CustomerPreference::create([
            'customer_id' => $customer->id,
            'key'         => 'noisy_signal',
            'value'       => 'maybe',
            'value_type'  => 'string',
            'confidence'  => 0.3,
            'source'      => 'inferred',
        ]);

        $profile = app(JetCrmContextService::class)->resolveCustomerProfile($customer->fresh());

        $this->assertTrue($profile['is_returning_customer']);
        $this->assertSame(6, $profile['total_bookings']);
        $this->assertSame('Pasir Pengaraian', $profile['preferred_pickup']);
        $this->assertSame('07:00', $profile['preferred_departure_time']);
        $this->assertArrayHasKey('customer_tier', $profile['preferences']);
        $this->assertSame('silver', $profile['preferences']['customer_tier']['value']);
        $this->assertArrayNotHasKey('noisy_signal', $profile['preferences']);
    }

    public function test_resolve_customer_profile_marks_new_customer_when_no_bookings(): void
    {
        config(['chatbot.crm.ai_context.enabled' => true]);

        $customer = Customer::create([
            'name'           => 'Bu Sari',
            'phone_e164'     => '+6285555555555',
            'status'         => 'active',
            'total_bookings' => 0,
        ]);

        $profile = app(JetCrmContextService::class)->resolveCustomerProfile($customer->fresh());

        $this->assertFalse($profile['is_returning_customer']);
        $this->assertSame(0, $profile['total_bookings']);
        $this->assertSame([], $profile['preferences']);
    }

    public function test_resolve_customer_profile_returns_empty_when_disabled(): void
    {
        config(['chatbot.crm.ai_context.enabled' => false]);

        $customer = Customer::create([
            'name'       => 'Disabled',
            'phone_e164' => '+6281111111111',
            'status'     => 'active',
        ]);

        $this->assertSame([], app(JetCrmContextService::class)->resolveCustomerProfile($customer));
    }

    public function test_it_caches_resolved_context_per_customer(): void
    {
        config([
            'chatbot.crm.ai_context.enabled' => true,
            'chatbot.crm.ai_context.ttl_seconds' => 600,
        ]);

        $customer = Customer::create([
            'name' => 'Nerry',
            'phone_e164' => '+6281234567890',
            'status' => 'active',
        ]);

        $crmContact = CrmContact::create([
            'customer_id' => $customer->id,
            'provider' => 'hubspot',
            'external_contact_id' => 'cache-789',
            'sync_status' => 'synced',
            'sync_payload' => [
                'id' => 'cache-789',
                'properties' => ['company' => 'First'],
            ],
        ]);

        $service = app(JetCrmContextService::class);

        $first = $service->resolveForCustomer($customer->fresh());
        $this->assertSame('First', $first['company']);

        $crmContact->update([
            'sync_payload' => [
                'id' => 'cache-789',
                'properties' => ['company' => 'Second'],
            ],
        ]);

        $second = $service->resolveForCustomer($customer->fresh());
        $this->assertSame('First', $second['company']);
    }
}
