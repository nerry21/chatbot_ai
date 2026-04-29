<?php

namespace Tests\Unit\Services\Chatbot;

use App\Models\Customer;
use App\Services\Chatbot\LlmAgentRateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class LlmAgentRateLimiterTest extends TestCase
{
    use RefreshDatabase;

    private function makeCustomer(string $phone = '+6281234567890'): Customer
    {
        return Customer::create([
            'name' => 'Rate Test '.uniqid(),
            'phone_e164' => $phone,
            'status' => 'active',
        ]);
    }

    private function clearLimiterFor(Customer $customer): void
    {
        RateLimiter::clear('llm_agent_rate:'.$customer->id);
    }

    public function test_allows_first_call(): void
    {
        $customer = $this->makeCustomer();
        $this->clearLimiterFor($customer);

        $limiter = app(LlmAgentRateLimiter::class);

        $this->assertTrue($limiter->shouldAllow($customer));
    }

    public function test_allows_within_limit(): void
    {
        $customer = $this->makeCustomer('+6281234567891');
        $this->clearLimiterFor($customer);

        $limiter = app(LlmAgentRateLimiter::class);

        for ($i = 0; $i < 30; $i++) {
            $this->assertTrue(
                $limiter->shouldAllow($customer),
                "Call #{$i} should be allowed within limit",
            );
        }
    }

    public function test_blocks_after_max_calls(): void
    {
        $customer = $this->makeCustomer('+6281234567892');
        $this->clearLimiterFor($customer);

        $limiter = app(LlmAgentRateLimiter::class);

        for ($i = 0; $i < 30; $i++) {
            $limiter->shouldAllow($customer);
        }

        $this->assertFalse($limiter->shouldAllow($customer), '31st call should be blocked');
    }

    public function test_separate_customers_separate_limits(): void
    {
        $customerA = $this->makeCustomer('+6281234567893');
        $customerB = $this->makeCustomer('+6281234567894');
        $this->clearLimiterFor($customerA);
        $this->clearLimiterFor($customerB);

        $limiter = app(LlmAgentRateLimiter::class);

        for ($i = 0; $i < 30; $i++) {
            $limiter->shouldAllow($customerA);
        }
        $this->assertFalse($limiter->shouldAllow($customerA), 'Customer A should be capped');

        $this->assertTrue(
            $limiter->shouldAllow($customerB),
            'Customer B should still be allowed (independent bucket)',
        );
    }

    public function test_remaining_calls_decreases(): void
    {
        $customer = $this->makeCustomer('+6281234567895');
        $this->clearLimiterFor($customer);

        $limiter = app(LlmAgentRateLimiter::class);

        $this->assertSame(30, $limiter->remainingCalls($customer));

        for ($i = 0; $i < 5; $i++) {
            $limiter->shouldAllow($customer);
        }

        $this->assertSame(25, $limiter->remainingCalls($customer));
    }

    public function test_decay_resets_limit(): void
    {
        $customer = $this->makeCustomer('+6281234567896');
        $this->clearLimiterFor($customer);

        $limiter = app(LlmAgentRateLimiter::class);

        for ($i = 0; $i < 30; $i++) {
            $limiter->shouldAllow($customer);
        }
        $this->assertFalse($limiter->shouldAllow($customer));

        // Simulate decay by clearing the bucket directly.
        $this->clearLimiterFor($customer);

        $this->assertTrue(
            $limiter->shouldAllow($customer),
            'After decay (bucket clear), customer should be allowed again',
        );
    }
}
