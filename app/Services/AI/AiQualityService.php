<?php

namespace App\Services\AI;

use App\Models\AiLog;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * AiQualityService — Tahap 10
 *
 * Aggregates AI quality metrics from the ai_logs table.
 * Provides simple, query-based overviews and recent problem logs for
 * the admin dashboard and AI logs page.
 *
 * All methods are read-only — no writes to any table.
 * Data is only meaningful for log rows created after Tahap 10 was deployed
 * (i.e. rows that have quality_label and knowledge_hits populated).
 */
class AiQualityService
{
    /**
     * Build an overview of AI quality metrics for the given time window.
     *
     * @param  int  $days  Number of past days to include (default from config).
     * @return array{
     *     window_days: int,
     *     from: string,
     *     summary: array{
     *         total_logs: int,
     *         failed_logs: int,
     *         low_confidence_intents: int,
     *         reply_fallbacks: int,
     *         faq_direct_hits: int,
     *         knowledge_hit_logs: int,
     *     },
     *     task_breakdown: array<string, array{total: int, failed: int}>,
     *     quality_rate: float,
     * }
     */
    public function buildOverview(int $days = 0): array
    {
        if ($days <= 0) {
            $days = (int) config('chatbot.ai_quality.dashboard_days_window', 7);
        }

        $from = Carbon::now()->subDays($days)->startOfDay();

        $base = AiLog::where('created_at', '>=', $from);

        $totalLogs  = (clone $base)->count();
        $failedLogs = (clone $base)->where('status', 'failed')->count();

        $lowConfidenceIntents = (clone $base)
            ->where('task_type', 'intent')
            ->where('quality_label', 'low_confidence')
            ->count();

        $replyFallbacks = (clone $base)
            ->where('task_type', 'reply')
            ->where('quality_label', 'fallback')
            ->count();

        $faqDirectHits = (clone $base)
            ->where('quality_label', 'faq_direct')
            ->count();

        $knowledgeHitLogs = (clone $base)
            ->whereNotNull('knowledge_hits')
            ->count();

        // Per-task breakdown
        $taskRows = (clone $base)
            ->selectRaw('task_type, status, COUNT(*) as cnt')
            ->groupBy('task_type', 'status')
            ->get();

        $taskBreakdown = [];
        foreach ($taskRows as $row) {
            $task = $row->task_type ?? 'unknown';
            if (! isset($taskBreakdown[$task])) {
                $taskBreakdown[$task] = ['total' => 0, 'failed' => 0];
            }
            $taskBreakdown[$task]['total'] += (int) $row->cnt;
            if ($row->status === 'failed') {
                $taskBreakdown[$task]['failed'] += (int) $row->cnt;
            }
        }
        ksort($taskBreakdown);

        // Simple quality rate: proportion of successful non-fallback reply logs
        $replyTotal    = $taskBreakdown['reply']['total'] ?? 0;
        $replyProblems = $replyFallbacks + ($taskBreakdown['reply']['failed'] ?? 0);
        $qualityRate   = $replyTotal > 0
            ? round(max(0.0, ($replyTotal - $replyProblems) / $replyTotal) * 100, 1)
            : 100.0;

        return [
            'window_days' => $days,
            'from'        => $from->toDateString(),
            'summary'     => [
                'total_logs'            => $totalLogs,
                'failed_logs'           => $failedLogs,
                'low_confidence_intents' => $lowConfidenceIntents,
                'reply_fallbacks'       => $replyFallbacks,
                'faq_direct_hits'       => $faqDirectHits,
                'knowledge_hit_logs'    => $knowledgeHitLogs,
            ],
            'task_breakdown' => $taskBreakdown,
            'quality_rate'   => $qualityRate,
        ];
    }

    /**
     * Return recent ai_log rows flagged as low confidence intent.
     *
     * @param  int  $limit
     * @return Collection<int, AiLog>
     */
    public function recentLowConfidenceLogs(int $limit = 0): Collection
    {
        if ($limit <= 0) {
            $limit = (int) config('chatbot.ai_quality.sample_recent_logs_limit', 20);
        }

        return AiLog::where('task_type', 'intent')
            ->where('quality_label', 'low_confidence')
            ->with('conversation')
            ->latest()
            ->limit($limit)
            ->get();
    }

    /**
     * Return recent ai_log rows with status = 'failed'.
     *
     * @param  int  $limit
     * @return Collection<int, AiLog>
     */
    public function recentFailedAiTasks(int $limit = 0): Collection
    {
        if ($limit <= 0) {
            $limit = (int) config('chatbot.ai_quality.sample_recent_logs_limit', 20);
        }

        return AiLog::where('status', 'failed')
            ->with('conversation')
            ->latest()
            ->limit($limit)
            ->get();
    }

    /**
     * Determine a simple traffic-light quality status string.
     * Based on the quality_rate from buildOverview().
     *
     * @param  float  $qualityRate  0–100
     * @return 'good'|'warning'|'poor'
     */
    public function qualityStatus(float $qualityRate): string
    {
        if ($qualityRate >= 80.0) {
            return 'good';
        }
        if ($qualityRate >= 60.0) {
            return 'warning';
        }
        return 'poor';
    }
}
