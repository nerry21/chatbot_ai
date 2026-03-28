<?php

namespace Tests\Feature\Benchmark;

use App\Services\AI\Evaluation\ChatbotBenchmarkService;
use App\Support\Benchmark\ChatbotBenchmarkCaseRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ChatbotBenchmarkServiceTest extends TestCase
{
    #[DataProvider('benchmarkCaseProvider')]
    public function test_each_benchmark_case_passes(string $caseId): void
    {
        $result = app(ChatbotBenchmarkService::class)->runCase($caseId);

        $this->assertTrue(
            $result['passed'],
            json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        );
    }

    public function test_it_builds_summary_for_full_benchmark_suite(): void
    {
        $result = app(ChatbotBenchmarkService::class)->run();

        $this->assertGreaterThan(0, $result['total_cases']);
        $this->assertSame($result['total_cases'], $result['passed']);
        $this->assertSame(0, $result['failed']);
        $this->assertArrayHasKey('intent_understanding', $result['tag_breakdown']);
        $this->assertArrayHasKey('grounded_response_correctness', $result['tag_breakdown']);
        $this->assertSame(2, $result['fallback_metrics']['fallback_used']);
    }

    public function test_it_can_filter_benchmark_by_category(): void
    {
        $result = app(ChatbotBenchmarkService::class)->run('repetitive_reply_prevention');

        $this->assertSame(2, $result['total_cases']);
        $this->assertSame(2, $result['passed']);
        $this->assertSame(0, $result['failed']);
        $this->assertArrayHasKey('repetitive_reply_prevention', $result['tag_breakdown']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function benchmarkCaseProvider(): array
    {
        $cases = (new ChatbotBenchmarkCaseRepository())->all();
        $dataset = [];

        foreach ($cases as $case) {
            $dataset[(string) $case['id']] = [(string) $case['id']];
        }

        return $dataset;
    }
}
