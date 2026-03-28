<?php

namespace Tests\Feature\Benchmark;

use Tests\TestCase;

class ChatbotBenchmarkCommandTest extends TestCase
{
    public function test_chatbot_benchmark_command_runs_successfully(): void
    {
        $this->artisan('chatbot:benchmark')
            ->expectsOutputToContain('Total cases')
            ->expectsOutputToContain('Passed')
            ->expectsOutputToContain('Failed')
            ->expectsOutputToContain('Failure categories')
            ->assertExitCode(0);
    }

    public function test_chatbot_benchmark_command_can_filter_category(): void
    {
        $this->artisan('chatbot:benchmark --category=repetitive_reply_prevention')
            ->expectsOutputToContain('repeat_prevention_same_context_blocked')
            ->expectsOutputToContain('repeat_prevention_followup_context_changed_allowed')
            ->assertExitCode(0);
    }
}
