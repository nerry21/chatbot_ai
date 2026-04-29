<?php

namespace App\Services\Chatbot;

use App\Models\Customer;
use App\Support\WaLog;
use Illuminate\Support\Facades\RateLimiter;

class LlmAgentRateLimiter
{
    private const MAX_CALLS = 30;
    private const DECAY_SECONDS = 3600;

    public function shouldAllow(Customer $customer): bool
    {
        $key = $this->key($customer);
        $hits = RateLimiter::attempt(
            $key,
            self::MAX_CALLS,
            function () { /* no-op — we just want the count */ },
            self::DECAY_SECONDS,
        );

        if (! $hits) {
            WaLog::warning('[LlmAgentRateLimiter] Rate limit exceeded — fallback to rule-based', [
                'customer_id' => $customer->id,
                'phone_e164' => $customer->phone_e164,
                'max_calls' => self::MAX_CALLS,
                'decay_seconds' => self::DECAY_SECONDS,
            ]);
            return false;
        }

        return true;
    }

    public function remainingCalls(Customer $customer): int
    {
        $key = $this->key($customer);
        return max(0, self::MAX_CALLS - RateLimiter::attempts($key));
    }

    private function key(Customer $customer): string
    {
        return 'llm_agent_rate:'.$customer->id;
    }
}
