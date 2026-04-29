<?php

namespace Tests\Unit\Chatbot;

use App\Models\Customer;
use App\Models\CustomerPreference;
use App\Services\Chatbot\LlmAgentPromptBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class LlmAgentPromptBuilderTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    public function test_prompt_emits_new_customer_block_when_no_history(): void
    {
        config(['chatbot.crm.ai_context.enabled' => true]);

        $customer = Customer::create([
            'name'           => 'Pak Budi',
            'phone_e164'     => '+6281234567890',
            'status'         => 'active',
            'total_bookings' => 0,
        ]);

        $prompt = app(LlmAgentPromptBuilder::class)->buildSystemPrompt($customer);

        $this->assertStringContainsString('Status: NEW CUSTOMER', $prompt);
        $this->assertStringContainsString('INSTRUKSI NEW CUSTOMER', $prompt);
        $this->assertStringNotContainsString('INSTRUKSI WARMTH', $prompt);
    }

    public function test_prompt_emits_warmth_block_for_returning_customer(): void
    {
        config(['chatbot.crm.ai_context.enabled' => true]);

        $customer = Customer::create([
            'name'                     => 'Pak Budi',
            'phone_e164'               => '+6281234567890',
            'status'                   => 'active',
            'total_bookings'           => 4,
            'preferred_pickup'         => 'Pasir Pengaraian',
            'preferred_destination'    => 'Pekanbaru',
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

        CustomerPreference::create([
            'customer_id' => $customer->id,
            'key'         => 'preferred_seat_specific',
            'value'       => 'CC1',
            'value_type'  => 'string',
            'confidence'  => 0.7,
            'source'      => 'inferred',
        ]);

        $prompt = app(LlmAgentPromptBuilder::class)->buildSystemPrompt($customer);

        $this->assertStringContainsString('RETURNING CUSTOMER (4x booking)', $prompt);
        $this->assertStringContainsString('INSTRUKSI WARMTH', $prompt);
        $this->assertStringContainsString('Pasir Pengaraian', $prompt);
        $this->assertStringContainsString('07:00', $prompt);
        $this->assertStringContainsString('CC1', $prompt);
        $this->assertStringContainsString('silver', $prompt);
        $this->assertStringNotContainsString('INSTRUKSI NEW CUSTOMER', $prompt);
    }

    public function test_prompt_includes_milestone_apology_when_milestone_set(): void
    {
        config(['chatbot.crm.ai_context.enabled' => true]);

        $customer = Customer::create([
            'name'           => 'Pak Budi',
            'phone_e164'     => '+6281234567890',
            'status'         => 'active',
            'total_bookings' => 5,
        ]);

        CustomerPreference::create([
            'customer_id' => $customer->id,
            'key'         => 'total_lifetime_bookings_milestone',
            'value'       => '5_bookings',
            'value_type'  => 'string',
            'confidence'  => 1.0,
            'source'      => 'inferred',
        ]);

        $prompt = app(LlmAgentPromptBuilder::class)->buildSystemPrompt($customer);

        $this->assertStringContainsString('milestone 5_bookings', $prompt);
    }
}
