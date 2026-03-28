<?php

namespace App\Console\Commands;

use App\Services\AI\Evaluation\ChatbotBenchmarkService;
use Illuminate\Console\Command;

class ChatbotBenchmarkCommand extends Command
{
    protected $signature = 'chatbot:benchmark
                            {--category= : Jalankan hanya kategori benchmark tertentu.}';

    protected $description = 'Run deterministic internal benchmarks for the chatbot pipeline.';

    public function handle(ChatbotBenchmarkService $benchmark): int
    {
        $category = is_string($this->option('category')) ? trim((string) $this->option('category')) : null;
        $result = $benchmark->run($category !== '' ? $category : null);

        if ($result['total_cases'] === 0) {
            $this->error('Tidak ada benchmark case yang cocok dengan filter tersebut.');

            return self::FAILURE;
        }

        $rows = array_map(
            static fn (array $case): array => [
                $case['passed'] ? 'PASS' : 'FAIL',
                $case['id'],
                implode(', ', $case['tags'] ?? []),
                $case['failure_category'] ?? '-',
                $case['details'] ?? '-',
            ],
            $result['cases'],
        );

        $this->line('');
        $this->table(['Status', 'Case', 'Kategori', 'Gagal', 'Detail'], $rows);

        $this->line('');
        $this->line('Total cases : ' . $result['total_cases']);
        $this->line('Passed      : ' . $result['passed']);
        $this->line('Failed      : ' . $result['failed']);
        $this->line('Fallback    : ' . $result['fallback_metrics']['fallback_used'] . '/' . $result['fallback_metrics']['relevant_cases']
            . ' (' . $result['fallback_metrics']['fallback_rate_percent'] . '%)');

        $this->line('');
        $this->line('Tag breakdown:');

        foreach ($result['tag_breakdown'] as $tag => $tagSummary) {
            $this->line(sprintf(
                '- %s: %d case, %d pass, %d fail',
                $tag,
                $tagSummary['cases'],
                $tagSummary['passed'],
                $tagSummary['failed'],
            ));
        }

        $this->line('');
        if ($result['failure_categories'] === []) {
            $this->line('Failure categories: none');
        } else {
            $this->line('Failure categories:');

            foreach ($result['failure_categories'] as $failureCategory => $count) {
                $this->line(sprintf('- %s: %d', $failureCategory, $count));
            }
        }

        $this->line('');

        return $result['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
