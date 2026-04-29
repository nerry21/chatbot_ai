<?php

namespace Tests\Unit\Chatbot;

use App\Models\Customer;
use App\Models\KnowledgeArticle;
use App\Services\Booking\FareCalculatorService;
use App\Services\Booking\RouteValidationService;
use App\Services\Booking\SeatAvailabilityService;
use App\Services\Chatbot\LlmAgentToolRegistry;
use App\Services\CRM\CustomerPreferenceUpdaterService;
use App\Services\Knowledge\KnowledgeBaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Tests\TestCase;

class LlmAgentToolRegistryTest extends TestCase
{
    use RefreshDatabase;

    private function registry(): LlmAgentToolRegistry
    {
        return app(LlmAgentToolRegistry::class);
    }

    public function test_returns_tools_schema_with_8_tools(): void
    {
        $schema = $this->registry()->getToolsSchema();

        $this->assertCount(8, $schema);

        $names = [];
        foreach ($schema as $entry) {
            $this->assertSame('function', $entry['type'] ?? null);
            $this->assertNotEmpty($entry['function']['name'] ?? null);
            $names[] = $entry['function']['name'];
        }

        $this->assertSame(
            [
                'get_fare_for_route',
                'check_seat_availability',
                'search_knowledge_base',
                'get_route_info',
                'escalate_to_admin',
                'get_customer_preferences',
                'acknowledge_milestone',
                'record_customer_preference',
            ],
            $names,
        );
    }

    private function makeCustomer(array $attrs = []): Customer
    {
        return Customer::create(array_merge([
            'name'       => 'Test Customer',
            'phone_e164' => '+62812345'.rand(10000, 99999),
            'status'     => 'active',
        ], $attrs));
    }

    public function test_get_customer_preferences_returns_error_without_customer(): void
    {
        $result = $this->registry()->execute('get_customer_preferences', []);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame(0, $result['count']);
        $this->assertSame([], $result['preferences']);
    }

    public function test_get_customer_preferences_returns_all_reliable_prefs(): void
    {
        $customer = $this->makeCustomer();
        $updater = app(CustomerPreferenceUpdaterService::class);

        $updater->upsertPreference($customer, 'language_style', 'formal', 'string',
            CustomerPreferenceUpdaterService::SOURCE_EXPLICIT);
        $updater->upsertPreference($customer, 'preferred_greeting_style', 'Mbak', 'string',
            CustomerPreferenceUpdaterService::SOURCE_EXPLICIT);
        $updater->upsertPreference($customer, 'child_traveler', 'true', 'bool',
            CustomerPreferenceUpdaterService::SOURCE_INFERRED, [], 0.3);

        $result = $this->registry()->execute('get_customer_preferences', [], $customer);

        $this->assertSame(2, $result['count']);
        $this->assertArrayHasKey('language_style', $result['preferences']);
        $this->assertArrayHasKey('preferred_greeting_style', $result['preferences']);
        $this->assertArrayNotHasKey('child_traveler', $result['preferences']);
        $this->assertSame('formal', $result['preferences']['language_style']['value']);
    }

    public function test_get_customer_preferences_filters_by_keys(): void
    {
        $customer = $this->makeCustomer();
        $updater = app(CustomerPreferenceUpdaterService::class);

        $updater->upsertPreference($customer, 'language_style', 'santai', 'string',
            CustomerPreferenceUpdaterService::SOURCE_EXPLICIT);
        $updater->upsertPreference($customer, 'preferred_greeting_style', 'Kak', 'string',
            CustomerPreferenceUpdaterService::SOURCE_EXPLICIT);

        $result = $this->registry()->execute(
            'get_customer_preferences',
            ['keys' => ['language_style']],
            $customer,
        );

        $this->assertSame(1, $result['count']);
        $this->assertArrayHasKey('language_style', $result['preferences']);
        $this->assertArrayNotHasKey('preferred_greeting_style', $result['preferences']);
    }

    public function test_get_customer_preferences_returns_empty_when_no_prefs(): void
    {
        $customer = $this->makeCustomer();

        $result = $this->registry()->execute('get_customer_preferences', [], $customer);

        $this->assertSame(0, $result['count']);
        $this->assertSame([], $result['preferences']);
    }

    public function test_record_customer_preference_returns_error_without_customer(): void
    {
        $result = $this->registry()->execute('record_customer_preference', [
            'key'              => 'language_style',
            'value'            => 'formal',
            'confidence_level' => 'explicit',
        ]);

        $this->assertArrayHasKey('error', $result);
        $this->assertFalse($result['recorded']);
    }

    public function test_record_customer_preference_explicit_sets_confidence_1_0(): void
    {
        $customer = $this->makeCustomer();

        $result = $this->registry()->execute('record_customer_preference', [
            'key'              => 'language_style',
            'value'            => 'formal',
            'confidence_level' => 'explicit',
        ], $customer);

        $this->assertTrue($result['recorded']);
        $this->assertEqualsWithDelta(1.0, $result['confidence'], 0.001);
        $this->assertSame('explicit', $result['source']);
    }

    public function test_record_customer_preference_inferred_sets_confidence_0_5(): void
    {
        $customer = $this->makeCustomer();

        $result = $this->registry()->execute('record_customer_preference', [
            'key'              => 'preferred_greeting_style',
            'value'            => 'Mbak',
            'confidence_level' => 'inferred',
        ], $customer);

        $this->assertTrue($result['recorded']);
        $this->assertEqualsWithDelta(0.5, $result['confidence'], 0.001);
        $this->assertSame('inferred', $result['source']);
    }

    public function test_record_customer_preference_rejects_non_whitelisted_key(): void
    {
        $customer = $this->makeCustomer();

        $result = $this->registry()->execute('record_customer_preference', [
            'key'              => 'random_garbage_key',
            'value'            => 'something',
            'confidence_level' => 'explicit',
        ], $customer);

        $this->assertArrayHasKey('error', $result);
        $this->assertFalse($result['recorded']);
        $this->assertStringContainsString('not in whitelist', $result['error']);
    }

    public function test_record_customer_preference_rejects_empty_value(): void
    {
        $customer = $this->makeCustomer();

        $result = $this->registry()->execute('record_customer_preference', [
            'key'              => 'language_style',
            'value'            => '   ',
            'confidence_level' => 'explicit',
        ], $customer);

        $this->assertArrayHasKey('error', $result);
        $this->assertFalse($result['recorded']);
        $this->assertStringContainsString('empty', $result['error']);
    }

    public function test_record_customer_preference_reinforces_on_repeat_same_value(): void
    {
        $customer = $this->makeCustomer();

        $this->registry()->execute('record_customer_preference', [
            'key'              => 'language_style',
            'value'            => 'formal',
            'confidence_level' => 'inferred',
        ], $customer);

        $result = $this->registry()->execute('record_customer_preference', [
            'key'              => 'language_style',
            'value'            => 'formal',
            'confidence_level' => 'inferred',
        ], $customer);

        $this->assertTrue($result['recorded']);
        $this->assertEqualsWithDelta(0.6, $result['confidence'], 0.01);
    }

    public function test_record_customer_preference_invalidates_cache(): void
    {
        $customer = $this->makeCustomer();
        $cacheKey = 'jet_crm_profile_customer_'.$customer->id;

        Cache::put($cacheKey, ['stale' => 'data'], 600);
        $this->assertTrue(Cache::has($cacheKey));

        $this->registry()->execute('record_customer_preference', [
            'key'              => 'vip_indicator',
            'value'            => 'true',
            'confidence_level' => 'explicit',
        ], $customer);

        $this->assertFalse(Cache::has($cacheKey));
    }

    public function test_record_customer_preference_accepts_all_10_whitelisted_keys(): void
    {
        $customer = $this->makeCustomer();

        $keys = [
            'language_style', 'preferred_greeting_style', 'child_traveler',
            'elderly_traveler', 'luggage_pattern', 'frequent_companion',
            'preferred_service_type', 'vip_indicator', 'notes_freeform', 'internal_tags',
        ];

        foreach ($keys as $key) {
            $result = $this->registry()->execute('record_customer_preference', [
                'key'              => $key,
                'value'            => 'test_value',
                'confidence_level' => 'explicit',
            ], $customer);

            $this->assertTrue($result['recorded'], "Failed to record key: {$key}");
        }

        $this->assertSame(10, $customer->preferences()->count());
    }

    public function test_executes_get_fare_for_route_returns_amount(): void
    {
        $result = $this->registry()->execute('get_fare_for_route', [
            'pickup' => 'Pasir Pengaraian',
            'dropoff' => 'Pekanbaru',
        ]);

        $this->assertSame(150000, $result['amount']);
        $this->assertSame('Rp 150.000', $result['formatted']);
        $this->assertSame('Pasir Pengaraian', $result['pickup']);
        $this->assertSame('Pekanbaru', $result['dropoff']);
        $this->assertTrue($result['supported']);
    }

    public function test_executes_get_fare_for_route_returns_error_for_unsupported(): void
    {
        $result = $this->registry()->execute('get_fare_for_route', [
            'pickup' => 'Atlantis',
            'dropoff' => 'Mars',
        ]);

        $this->assertArrayHasKey('error', $result);
        $this->assertFalse($result['supported']);
    }

    public function test_executes_check_seat_availability(): void
    {
        $result = $this->registry()->execute('check_seat_availability', [
            'date' => '2026-05-01',
            'time' => '07:00',
        ]);

        $this->assertArrayHasKey('available_seats', $result);
        $this->assertIsArray($result['available_seats']);
        $this->assertArrayHasKey('count', $result);
        $this->assertArrayHasKey('has_capacity', $result);
        $this->assertSame(count($result['available_seats']), $result['count']);
    }

    public function test_executes_search_knowledge_base(): void
    {
        config(['chatbot.knowledge.enabled' => true]);

        KnowledgeArticle::create([
            'title' => 'Tarif Pekanbaru',
            'slug' => 'tarif-pekanbaru',
            'category' => 'tarif',
            'content' => 'Tarif perjalanan ke Pekanbaru adalah Rp 150.000 per kursi.',
            'keywords' => ['tarif', 'pekanbaru'],
            'is_active' => true,
        ]);

        $result = $this->registry()->execute('search_knowledge_base', [
            'query' => 'tarif pekanbaru',
        ]);

        $this->assertArrayHasKey('hits', $result);
        $this->assertArrayHasKey('count', $result);
        $this->assertGreaterThanOrEqual(1, $result['count']);
        $this->assertSame('Tarif Pekanbaru', $result['hits'][0]['title']);
    }

    public function test_executes_get_route_info_finds_location(): void
    {
        $result = $this->registry()->execute('get_route_info', [
            'query_text' => 'Saya di Pasir Pengaraian',
        ]);

        $this->assertSame('Pasir Pengaraian', $result['location']);
        $this->assertIsArray($result['supported_destinations']);
    }

    public function test_executes_get_route_info_returns_null_when_no_location(): void
    {
        $result = $this->registry()->execute('get_route_info', [
            'query_text' => 'XXXXXXXXX',
        ]);

        $this->assertNull($result['location']);
        $this->assertSame('Lokasi tidak terdeteksi', $result['message']);
    }

    public function test_executes_escalate_to_admin_returns_handoff(): void
    {
        $result = $this->registry()->execute('escalate_to_admin', [
            'reason' => 'refund request',
        ]);

        $this->assertTrue($result['handoff_triggered']);
        $this->assertSame('refund request', $result['reason']);
    }

    public function test_throws_on_unknown_tool(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->registry()->execute('nonexistent_tool', []);
    }
}
