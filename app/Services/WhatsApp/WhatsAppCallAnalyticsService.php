<?php

namespace App\Services\WhatsApp;

use App\Models\Conversation;
use App\Models\WhatsAppCallSession;
use App\Support\Transformers\CallAnalyticsTransformer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class WhatsAppCallAnalyticsService
{
    public function __construct(
        private readonly WhatsAppCallSessionService $callSessionService,
        private readonly CallAnalyticsTransformer $transformer,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function getGlobalSummary(array $filters = []): array
    {
        if (! $this->callSessionStorageReady()) {
            return $this->emptyGlobalSummaryPayload();
        }

        $this->backfillMissingAnalytics();

        $query = $this->baseQuery($filters);

        $totalCalls = (clone $query)->count();
        $completedCalls = $this->countByFinalStatus($query, WhatsAppCallSession::FINAL_STATUS_COMPLETED);
        $missedCalls = $this->countByFinalStatus($query, WhatsAppCallSession::FINAL_STATUS_MISSED);
        $rejectedCalls = $this->countByFinalStatus($query, WhatsAppCallSession::FINAL_STATUS_REJECTED);
        $failedCalls = $this->countByFinalStatus($query, WhatsAppCallSession::FINAL_STATUS_FAILED);
        $cancelledCalls = $this->countByFinalStatus($query, WhatsAppCallSession::FINAL_STATUS_CANCELLED);
        $permissionPendingCalls = $this->countByFinalStatus($query, WhatsAppCallSession::FINAL_STATUS_PERMISSION_PENDING);
        $inProgressCalls = $this->countByFinalStatus($query, WhatsAppCallSession::FINAL_STATUS_IN_PROGRESS);
        $totalDurationSeconds = (int) ((clone $query)->sum(DB::raw('COALESCE(duration_seconds, 0)')) ?? 0);
        $averageDurationSeconds = (int) round((float) ((clone $query)
            ->whereNotNull('duration_seconds')
            ->where('duration_seconds', '>', 0)
            ->avg('duration_seconds') ?? 0));
        $connectedCallCount = (clone $query)->where(function (Builder $builder): void {
            $builder->whereNotNull('connected_at')
                ->orWhereNotNull('answered_at');
        })->count();

        $permissionBaseQuery = (clone $query)->whereIn('permission_status', [
            WhatsAppCallSession::PERMISSION_REQUESTED,
            WhatsAppCallSession::PERMISSION_GRANTED,
            WhatsAppCallSession::PERMISSION_DENIED,
        ]);
        $permissionBaseCount = (clone $permissionBaseQuery)->count();
        $grantedPermissionCount = (clone $permissionBaseQuery)
            ->where('permission_status', WhatsAppCallSession::PERMISSION_GRANTED)
            ->count();

        $summary = [
            'total_calls' => $totalCalls,
            'completed_calls' => $completedCalls,
            'missed_calls' => $missedCalls,
            'rejected_calls' => $rejectedCalls,
            'failed_calls' => $failedCalls,
            'cancelled_calls' => $cancelledCalls,
            'permission_pending_calls' => $permissionPendingCalls,
            'in_progress_calls' => $inProgressCalls,
            'total_duration_seconds' => $totalDurationSeconds,
            'average_duration_seconds' => $averageDurationSeconds,
            'completion_rate' => $totalCalls > 0 ? ($completedCalls / $totalCalls) * 100 : 0,
            'missed_rate' => $totalCalls > 0 ? ($missedCalls / $totalCalls) * 100 : 0,
            'connected_call_count' => $connectedCallCount,
            'avg_answer_time_seconds' => $this->averageAnswerTimeSeconds($query),
            'avg_ringing_time_seconds' => $this->averageRingingTimeSeconds($query),
            'media_connected_rate' => null,
            'permission_acceptance_rate' => $permissionBaseCount > 0
                ? ($grantedPermissionCount / $permissionBaseCount) * 100
                : null,
        ];

        return [
            'summary' => $this->transformer->transformSummary($summary),
            'outcome_breakdown' => $this->getOutcomeBreakdown($filters),
            'daily_trend' => $this->getDailyTrend($filters),
            'agent_breakdown' => $this->getAgentBreakdown($filters),
            'capabilities' => $this->transformer->capabilities(),
            'future_metrics' => [
                'connected_call_count' => $connectedCallCount,
                'avg_answer_time_seconds' => $summary['avg_answer_time_seconds'],
                'avg_ringing_time_seconds' => $summary['avg_ringing_time_seconds'],
                'media_connected_rate' => null,
                'permission_acceptance_rate' => $summary['permission_acceptance_rate'],
            ],
        ];
    }

    /**
     * @param  Model|int|string  $conversation
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function getConversationCallHistory($conversation, array $filters = []): array
    {
        if (! $this->callSessionStorageReady()) {
            return $this->emptyConversationHistoryPayload();
        }

        $conversationId = $this->resolveConversationId($conversation);
        if ($conversationId === null) {
            return $this->emptyConversationHistoryPayload();
        }

        $this->backfillMissingAnalytics();

        $filters['conversation_id'] = $conversationId;
        $limit = max(1, min(50, (int) ($filters['limit'] ?? 10)));
        $query = $this->baseQuery($filters)
            ->where('conversation_id', $conversationId);

        $items = $this->applyRecentOrdering(clone $query)
            ->limit($limit)
            ->get()
            ->map(fn (WhatsAppCallSession $session): array => $this->transformCallSession($session))
            ->values()
            ->all();

        $latest = $this->applyRecentOrdering(clone $query)->first();

        return [
            'call_history_summary' => $this->transformer->transformConversationHistorySummary([
                'total_calls' => (clone $query)->count(),
                'last_call_status' => $latest?->resolvedFinalStatus(),
                'last_call_at' => $latest?->started_at?->toIso8601String() ?? $latest?->created_at?->toIso8601String(),
                'last_call_duration_seconds' => $latest?->getDurationSeconds(),
            ]),
            'call_history' => $items,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function getRecentCalls(array $filters = []): array
    {
        if (! $this->callSessionStorageReady()) {
            return [];
        }

        $this->backfillMissingAnalytics();

        $limit = max(1, min(50, (int) ($filters['limit'] ?? 10)));

        return $this->applyRecentOrdering($this->baseQuery($filters))
            ->limit($limit)
            ->get()
            ->map(fn (WhatsAppCallSession $session): array => $this->transformCallSession($session))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function getOutcomeBreakdown(array $filters = []): array
    {
        if (! $this->callSessionStorageReady()) {
            return [];
        }

        $this->backfillMissingAnalytics();

        $query = $this->baseQuery($filters);
        $total = (clone $query)->count();

        $rows = (clone $query)
            ->select('final_status')
            ->selectRaw('COUNT(*) as aggregate_count')
            ->groupBy('final_status')
            ->orderByDesc('aggregate_count')
            ->get();

        return $rows->map(function (WhatsAppCallSession $row) use ($total): array {
            $count = (int) ($row->getAttribute('aggregate_count') ?? 0);

            return $this->transformer->transformOutcomeRow([
                'final_status' => $row->final_status,
                'count' => $count,
                'percentage' => $total > 0 ? ($count / $total) * 100 : 0,
            ]);
        })->values()->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function getDailyTrend(array $filters = []): array
    {
        if (! $this->callSessionStorageReady()) {
            return [];
        }

        $this->backfillMissingAnalytics();

        $query = $this->baseQuery($filters);

        return (clone $query)
            ->selectRaw('DATE(COALESCE(started_at, created_at)) as call_date')
            ->selectRaw('COUNT(*) as total_calls')
            ->selectRaw(sprintf(
                'SUM(CASE WHEN final_status = "%s" THEN 1 ELSE 0 END) as completed_calls',
                WhatsAppCallSession::FINAL_STATUS_COMPLETED,
            ))
            ->selectRaw(sprintf(
                'SUM(CASE WHEN final_status = "%s" THEN 1 ELSE 0 END) as missed_calls',
                WhatsAppCallSession::FINAL_STATUS_MISSED,
            ))
            ->selectRaw(sprintf(
                'SUM(CASE WHEN final_status = "%s" THEN 1 ELSE 0 END) as failed_calls',
                WhatsAppCallSession::FINAL_STATUS_FAILED,
            ))
            ->selectRaw('SUM(COALESCE(duration_seconds, 0)) as total_duration_seconds')
            ->groupBy('call_date')
            ->orderBy('call_date')
            ->get()
            ->map(fn (WhatsAppCallSession $row): array => $this->transformer->transformDailyTrendRow([
                'date' => (string) $row->getAttribute('call_date'),
                'total_calls' => (int) ($row->getAttribute('total_calls') ?? 0),
                'completed_calls' => (int) ($row->getAttribute('completed_calls') ?? 0),
                'missed_calls' => (int) ($row->getAttribute('missed_calls') ?? 0),
                'failed_calls' => (int) ($row->getAttribute('failed_calls') ?? 0),
                'total_duration_seconds' => (int) ($row->getAttribute('total_duration_seconds') ?? 0),
            ]))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function getAgentBreakdown(array $filters = []): array
    {
        if (! $this->callSessionStorageReady()) {
            return [];
        }

        $this->backfillMissingAnalytics();

        $query = $this->baseQuery($filters);

        return (clone $query)
            ->leftJoin('users', 'users.id', '=', 'whatsapp_call_sessions.initiated_by_user_id')
            ->selectRaw('whatsapp_call_sessions.initiated_by_user_id as admin_user_id')
            ->selectRaw('COALESCE(users.name, "Unknown") as admin_name')
            ->selectRaw('COUNT(*) as total_calls')
            ->selectRaw(sprintf(
                'SUM(CASE WHEN final_status = "%s" THEN 1 ELSE 0 END) as completed_calls',
                WhatsAppCallSession::FINAL_STATUS_COMPLETED,
            ))
            ->selectRaw(sprintf(
                'SUM(CASE WHEN final_status = "%s" THEN 1 ELSE 0 END) as missed_calls',
                WhatsAppCallSession::FINAL_STATUS_MISSED,
            ))
            ->selectRaw(sprintf(
                'SUM(CASE WHEN final_status = "%s" THEN 1 ELSE 0 END) as failed_calls',
                WhatsAppCallSession::FINAL_STATUS_FAILED,
            ))
            ->selectRaw('SUM(COALESCE(duration_seconds, 0)) as total_duration_seconds')
            ->groupBy('whatsapp_call_sessions.initiated_by_user_id', 'users.name')
            ->orderByDesc('total_calls')
            ->limit(10)
            ->get()
            ->map(fn (WhatsAppCallSession $row): array => $this->transformer->transformAgentBreakdownRow([
                'admin_user_id' => $row->getAttribute('admin_user_id'),
                'admin_name' => $row->getAttribute('admin_name'),
                'total_calls' => (int) ($row->getAttribute('total_calls') ?? 0),
                'completed_calls' => (int) ($row->getAttribute('completed_calls') ?? 0),
                'missed_calls' => (int) ($row->getAttribute('missed_calls') ?? 0),
                'failed_calls' => (int) ($row->getAttribute('failed_calls') ?? 0),
                'total_duration_seconds' => (int) ($row->getAttribute('total_duration_seconds') ?? 0),
            ]))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function transformCallSession(WhatsAppCallSession $session): array
    {
        return $this->transformer->transformSession($session);
    }

    /**
     * @return array<string, mixed>
     */
    public function getCapabilities(): array
    {
        return $this->transformer->capabilities();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function baseQuery(array $filters = []): Builder
    {
        $query = WhatsAppCallSession::query()
            ->with([
                'customer',
                'conversation.customer',
                'initiatedBy',
            ]);

        if (($conversationId = $this->resolveConversationId($filters['conversation_id'] ?? null)) !== null) {
            $query->where('conversation_id', $conversationId);
        }

        if (($status = $this->normalizeString($filters['final_status'] ?? null)) !== null) {
            $query->where('final_status', $status);
        }

        if (($callType = $this->normalizeString($filters['call_type'] ?? null)) !== null) {
            $query->where('call_type', $callType);
        }

        if (($adminUserId = $this->resolveInteger($filters['admin_user_id'] ?? null)) !== null) {
            $query->where('initiated_by_user_id', $adminUserId);
        }

        if (($startDate = $this->normalizeDate($filters['start_date'] ?? null)) !== null) {
            $query->whereDate(DB::raw('COALESCE(started_at, created_at)'), '>=', $startDate);
        }

        if (($endDate = $this->normalizeDate($filters['end_date'] ?? null)) !== null) {
            $query->whereDate(DB::raw('COALESCE(started_at, created_at)'), '<=', $endDate);
        }

        return $query;
    }

    private function countByFinalStatus(Builder $query, string $finalStatus): int
    {
        return (clone $query)->where('final_status', $finalStatus)->count();
    }

    private function applyRecentOrdering(Builder $query): Builder
    {
        return $query
            ->orderByRaw('COALESCE(started_at, created_at) DESC')
            ->orderByDesc('id');
    }

    private function averageAnswerTimeSeconds(Builder $query): ?int
    {
        $sessions = (clone $query)
            ->whereNotNull('started_at')
            ->where(function (Builder $builder): void {
                $builder->whereNotNull('connected_at')
                    ->orWhereNotNull('answered_at');
            })
            ->get(['started_at', 'connected_at', 'answered_at']);

        $durations = [];

        foreach ($sessions as $session) {
            $startedAt = $session->started_at;
            $connectedAt = $session->connectedAt();

            if ($startedAt === null || $connectedAt === null || $connectedAt->lt($startedAt)) {
                continue;
            }

            $durations[] = $startedAt->diffInSeconds($connectedAt);
        }

        if ($durations === []) {
            return null;
        }

        return (int) round(array_sum($durations) / count($durations));
    }

    private function averageRingingTimeSeconds(Builder $query): ?int
    {
        return $this->averageAnswerTimeSeconds($query);
    }

    private function backfillMissingAnalytics(): void
    {
        if (! $this->callSessionStorageReady()) {
            return;
        }

        WhatsAppCallSession::query()
            ->where(function (Builder $builder): void {
                $builder->whereNull('final_status')
                    ->orWhereNull('last_status_at')
                    ->orWhereNull('timeline_snapshot')
                    ->orWhere(function (Builder $durationBuilder): void {
                        $durationBuilder->whereNotNull('ended_at')
                            ->whereNull('duration_seconds');
                    });
            })
            ->orderBy('id')
            ->limit(50)
            ->get()
            ->each(fn (WhatsAppCallSession $session) => $this->callSessionService->syncSessionData($session));
    }

    private function callSessionStorageReady(): bool
    {
        return WhatsAppCallSession::isTableAvailable();
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyGlobalSummaryPayload(): array
    {
        return [
            'summary' => $this->transformer->transformSummary([
                'total_calls' => 0,
                'completed_calls' => 0,
                'missed_calls' => 0,
                'rejected_calls' => 0,
                'failed_calls' => 0,
                'cancelled_calls' => 0,
                'permission_pending_calls' => 0,
                'in_progress_calls' => 0,
                'total_duration_seconds' => 0,
                'average_duration_seconds' => 0,
                'completion_rate' => 0,
                'missed_rate' => 0,
                'connected_call_count' => 0,
                'avg_answer_time_seconds' => null,
                'avg_ringing_time_seconds' => null,
                'media_connected_rate' => null,
                'permission_acceptance_rate' => null,
            ]),
            'outcome_breakdown' => [],
            'daily_trend' => [],
            'agent_breakdown' => [],
            'capabilities' => $this->transformer->capabilities(),
            'future_metrics' => [
                'connected_call_count' => 0,
                'avg_answer_time_seconds' => null,
                'avg_ringing_time_seconds' => null,
                'media_connected_rate' => null,
                'permission_acceptance_rate' => null,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyConversationHistoryPayload(): array
    {
        return [
            'call_history_summary' => $this->transformer->transformConversationHistorySummary([
                'total_calls' => 0,
                'last_call_status' => null,
                'last_call_at' => null,
                'last_call_duration_seconds' => null,
            ]),
            'call_history' => [],
        ];
    }

    /**
     * @param  Model|int|string|null  $conversation
     */
    private function resolveConversationId($conversation): ?int
    {
        if ($conversation instanceof Conversation) {
            $key = $conversation->getKey();

            return is_numeric($key) ? (int) $key : null;
        }

        return $this->resolveInteger($conversation);
    }

    private function resolveInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }

    private function normalizeString(mixed $value): ?string
    {
        $normalized = trim((string) ($value ?? ''));

        return $normalized !== '' ? $normalized : null;
    }

    private function normalizeDate(mixed $value): ?string
    {
        $normalized = $this->normalizeString($value);

        if ($normalized === null) {
            return null;
        }

        try {
            return \Illuminate\Support\Carbon::parse($normalized)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
