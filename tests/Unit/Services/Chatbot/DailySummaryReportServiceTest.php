<?php

namespace Tests\Unit\Services\Chatbot;

use App\Models\AiLog;
use App\Services\Chatbot\DailySummaryReportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DailySummaryReportServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): DailySummaryReportService
    {
        return app(DailySummaryReportService::class);
    }

    public function test_generates_zero_stats_when_no_logs(): void
    {
        $report = $this->service()->generate(Carbon::create(2026, 4, 29, 12, 0, 0, 'Asia/Jakarta'));

        $this->assertSame(0, $report['stats']['total_calls']);
        $this->assertSame(0, $report['stats']['tier1']);
        $this->assertSame(0, $report['stats']['tier2']);
        $this->assertStringContainsString('Tidak ada chat AI hari ini', $report['stats']['status']);
    }

    public function test_aggregates_tier1_and_tier2_correctly(): void
    {
        $today = Carbon::create(2026, 4, 29, 10, 0, 0, 'Asia/Jakarta');
        Carbon::setTestNow($today);

        for ($i = 0; $i < 5; $i++) {
            AiLog::create([
                'task_type' => 'llm_agent',
                'status' => 'success',
                'provider' => 'openai',
                'model' => 'gpt-5.4-mini',
                'token_input' => 100,
                'token_output' => 50,
                'parsed_output' => ['tier' => 'tier1', 'tier_score' => 0, 'tier_reasons' => []],
            ]);
        }

        for ($i = 0; $i < 3; $i++) {
            AiLog::create([
                'task_type' => 'llm_agent',
                'status' => 'success',
                'provider' => 'openai',
                'model' => 'gpt-5.4',
                'token_input' => 200,
                'token_output' => 100,
                'parsed_output' => ['tier' => 'tier2', 'tier_score' => 50, 'tier_reasons' => ['vip']],
            ]);
        }

        $report = $this->service()->generate($today);

        $this->assertSame(8, $report['stats']['total_calls']);
        $this->assertSame(5, $report['stats']['tier1']);
        $this->assertSame(3, $report['stats']['tier2']);
        $this->assertSame(500 + 600, $report['stats']['total_tokens_in']);
        $this->assertSame(250 + 300, $report['stats']['total_tokens_out']);

        Carbon::setTestNow();
    }

    public function test_breakdown_tier2_reasons(): void
    {
        $today = Carbon::create(2026, 4, 29, 10, 0, 0, 'Asia/Jakarta');
        Carbon::setTestNow($today);

        AiLog::create([
            'task_type' => 'llm_agent',
            'status' => 'success',
            'provider' => 'openai',
            'model' => 'gpt-5.4',
            'parsed_output' => ['tier' => 'tier2', 'tier_score' => 50, 'tier_reasons' => ['vip', 'complaint_keyword']],
        ]);
        AiLog::create([
            'task_type' => 'llm_agent',
            'status' => 'success',
            'provider' => 'openai',
            'model' => 'gpt-5.4',
            'parsed_output' => ['tier' => 'tier2', 'tier_score' => 50, 'tier_reasons' => ['complaint_keyword']],
        ]);

        $report = $this->service()->generate($today);
        $reasons = $report['stats']['tier2_reasons'];

        $this->assertSame(1, $reasons['vip']);
        $this->assertSame(2, $reasons['complaint_keyword']);
        $this->assertSame(0, $reasons['sentiment_negative']);
        $this->assertSame(0, $reasons['multi_turn']);
        $this->assertSame(0, $reasons['escalation_pending']);

        Carbon::setTestNow();
    }

    public function test_only_includes_logs_within_date_range(): void
    {
        $today = Carbon::create(2026, 4, 29, 10, 0, 0, 'Asia/Jakarta');
        $yesterday = $today->copy()->subDay();

        Carbon::setTestNow($yesterday);
        AiLog::create([
            'task_type' => 'llm_agent',
            'status' => 'success',
            'provider' => 'openai',
            'model' => 'gpt-5.4-mini',
            'parsed_output' => ['tier' => 'tier1', 'tier_score' => 0, 'tier_reasons' => []],
        ]);

        Carbon::setTestNow($today);
        AiLog::create([
            'task_type' => 'llm_agent',
            'status' => 'success',
            'provider' => 'openai',
            'model' => 'gpt-5.4-mini',
            'parsed_output' => ['tier' => 'tier1', 'tier_score' => 0, 'tier_reasons' => []],
        ]);

        $report = $this->service()->generate($today);

        $this->assertSame(1, $report['stats']['total_calls'], 'Only today log should count');
        $this->assertSame(1, $report['stats']['tier1']);

        Carbon::setTestNow();
    }
}
