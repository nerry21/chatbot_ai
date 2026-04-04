<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Api\Mobile\Concerns\RespondsWithMobileJson;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\StatusUpdate;
use App\Models\StatusUpdateView;
use App\Services\Mobile\MobileAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StatusFeedController extends Controller
{
    use RespondsWithMobileJson;

    public function __construct(
        private readonly MobileAuthService $mobileAuthService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $customer = $this->mobileAuthService->currentCustomer($request);

        $statuses = StatusUpdate::query()
            ->with(['authorUser', 'views'])
            ->where('author_type', 'admin')
            ->whereIn('audience_scope', ['contacts_and_chatters', 'public'])
            ->active()
            ->latest('posted_at')
            ->get();

        $grouped = $statuses
            ->groupBy(static fn (StatusUpdate $item): int => (int) ($item->user_id ?? 0))
            ->map(function ($items) use ($customer): array {
                /** @var StatusUpdate|null $first */
                $first = $items->first();
                $ordered = $items->sortBy('posted_at')->values();
                $totalSegments = $ordered->count();

                return [
                    'author_id' => (int) ($first?->user_id ?? 0),
                    'author_name' => (string) ($first?->author_name ?: 'Admin Jet'),
                    'author_avatar_url' => null,
                    'has_unviewed' => $ordered->contains(
                        fn (StatusUpdate $status): bool => ! $status->views->contains('customer_id', $customer->id)
                    ),
                    'last_posted_at' => $ordered->last()?->posted_at?->toIso8601String(),
                    'statuses' => $ordered
                        ->map(
                            fn (StatusUpdate $status, int $index): array => $this->transformStatus(
                                $status,
                                $customer,
                                segmentIndex: $index,
                                segmentTotal: $totalSegments,
                            )
                        )
                        ->values()
                        ->all(),
                ];
            })
            ->values()
            ->all();

        return $this->successResponse('Feed status berhasil diambil.', [
            'items' => $grouped,
        ]);
    }

    public function show(Request $request, StatusUpdate $statusUpdate): JsonResponse
    {
        $customer = $this->mobileAuthService->currentCustomer($request);

        $this->ensureVisibleStatus($statusUpdate);

        $statusUpdate->load(['authorUser', 'views']);

        return $this->successResponse('Detail status berhasil diambil.', [
            'status' => $this->transformStatus($statusUpdate, $customer),
        ]);
    }

    public function markViewed(Request $request, StatusUpdate $statusUpdate): JsonResponse
    {
        $customer = $this->mobileAuthService->currentCustomer($request);

        $this->ensureVisibleStatus($statusUpdate);

        StatusUpdateView::query()->updateOrCreate(
            [
                'status_update_id' => $statusUpdate->id,
                'customer_id' => $customer->id,
            ],
            [
                'viewed_at' => now(),
            ],
        );

        $viewerCount = StatusUpdateView::query()
            ->where('status_update_id', $statusUpdate->id)
            ->count();

        return $this->successResponse('Status ditandai telah dilihat.', [
            'status_id' => (int) $statusUpdate->id,
            'viewer_count' => (int) $viewerCount,
        ]);
    }

    private function ensureVisibleStatus(StatusUpdate $statusUpdate): void
    {
        abort_unless($statusUpdate->author_type === 'admin', 404);
        abort_unless(in_array($statusUpdate->audience_scope, ['contacts_and_chatters', 'public'], true), 404);
        abort_unless((bool) $statusUpdate->is_active, 404);
        abort_unless($statusUpdate->posted_at !== null, 404);
        abort_if($statusUpdate->expires_at !== null && $statusUpdate->expires_at->isPast(), 404);
    }

    /**
     * @return array<string, mixed>
     */
    private function transformStatus(
        StatusUpdate $status,
        Customer $customer,
        int $segmentIndex = 0,
        int $segmentTotal = 1,
    ): array {
        $isViewed = $status->views->contains('customer_id', $customer->id);

        return [
            'id' => (int) $status->id,
            'author_id' => (int) ($status->user_id ?? 0),
            'author_name' => (string) $status->author_name,
            'status_type' => (string) $status->status_type,
            'text' => $status->text,
            'caption' => $status->caption,
            'background_color' => $status->background_color,
            'text_color' => $status->text_color,
            'font_style' => $status->font_style,
            'media_url' => $status->media_url,
            'media_mime_type' => $status->media_mime_type,
            'music_meta' => $status->music_meta ?? [],
            'posted_at' => $status->posted_at?->toIso8601String(),
            'expires_at' => $status->expires_at?->toIso8601String(),
            'is_viewed' => $isViewed,
            'viewer_count' => (int) $status->views->count(),
            'duration_seconds' => $status->duration_seconds,
            'segment_index' => $segmentIndex,
            'segment_total' => $segmentTotal,
        ];
    }
}
