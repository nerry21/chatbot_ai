<?php

namespace App\Http\Controllers\Api\AdminMobile;

use App\Http\Controllers\Api\AdminMobile\Concerns\RespondsWithAdminMobileJson;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreStatusUpdateRequest;
use App\Models\Customer;
use App\Models\StatusUpdate;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;

class StatusUpdateController extends Controller
{
    use RespondsWithAdminMobileJson;

    public function index(): JsonResponse
    {
        $statuses = StatusUpdate::query()
            ->with(['authorUser', 'authorCustomer'])
            ->withCount('views')
            ->where('author_type', 'admin')
            ->active()
            ->latest('posted_at')
            ->limit(50)
            ->get();

        return $this->successResponse('Feed pembaharuan status berhasil diambil.', [
            'my_statuses' => $statuses
                ->map(fn (StatusUpdate $status): array => $this->transformStatus($status))
                ->values()
                ->all(),
            'audience_summary' => [
                'eligible_viewers' => $this->eligibleViewersQuery()->count(),
                'rule' => 'contacts_and_chatters',
                'rule_label' => 'Kontak yang sudah ada di sistem / pernah chat dengan bot',
            ],
        ]);
    }

    public function store(StoreStatusUpdateRequest $request): JsonResponse
    {
        $adminUser = $request->attributes->get('admin_mobile_user');

        if (! $adminUser instanceof User) {
            return response()->json([
                'success' => false,
                'message' => 'Sesi admin mobile tidak valid.',
            ], 401);
        }

        $statusType = (string) $request->input('status_type');
        $uploadedFile = $request->file('media_file');

        [$disk, $path, $mimeType, $originalName, $sizeBytes] = $this->storeMediaFile(
            statusType: $statusType,
            uploadedFile: $uploadedFile,
        );

        $status = StatusUpdate::query()->create([
            'user_id' => (int) $adminUser->id,
            'author_type' => 'admin',
            'status_type' => $statusType,
            'text' => $request->input('text'),
            'caption' => $request->input('caption'),
            'background_color' => $request->input('background_color', '#7EC8A5'),
            'text_color' => $request->input('text_color', '#FFFFFF'),
            'font_style' => $request->input('font_style', 'default'),
            'media_disk' => $disk,
            'media_path' => $path,
            'media_mime_type' => $mimeType,
            'media_original_name' => $originalName,
            'media_size_bytes' => $sizeBytes,
            'music_meta' => $this->musicMetaFromRequest($request),
            'audience_scope' => 'contacts_and_chatters',
            'is_active' => true,
            'posted_at' => now(),
            'expires_at' => now()->addDay(),
        ]);

        $status->load(['authorUser', 'authorCustomer'])->loadCount('views');

        return $this->successResponse('Status berhasil dipublikasikan.', [
            'status' => $this->transformStatus($status),
            'audience_summary' => [
                'eligible_viewers' => $this->eligibleViewersQuery()->count(),
                'rule' => 'contacts_and_chatters',
            ],
        ], 201);
    }

    public function show(StatusUpdate $statusUpdate): JsonResponse
    {
        $statusUpdate->load(['authorUser', 'authorCustomer', 'views.customer'])
            ->loadCount('views');

        return $this->successResponse('Detail status berhasil diambil.', [
            'status' => $this->transformStatus($statusUpdate),
            'view_summary' => [
                'total_views' => $statusUpdate->views->count(),
                'recent_viewers' => $statusUpdate->views
                    ->sortByDesc('viewed_at')
                    ->take(20)
                    ->map(fn ($view): array => [
                        'customer_id' => (int) $view->customer_id,
                        'name' => (string) ($view->customer?->name ?: 'Customer'),
                        'phone_e164' => (string) ($view->customer?->phone_e164 ?: ''),
                        'viewed_at' => $view->viewed_at?->toIso8601String(),
                    ])
                    ->values()
                    ->all(),
            ],
        ]);
    }

    private function eligibleViewersQuery(): Builder
    {
        return Customer::query()
            ->whereNotNull('phone_e164')
            ->whereHas('conversations', function (Builder $query): void {
                $query->where('channel', 'whatsapp');
            });
    }

    /**
     * @return array{0: ?string, 1: ?string, 2: ?string, 3: ?string, 4: ?int}
     */
    private function storeMediaFile(
        string $statusType,
        ?UploadedFile $uploadedFile,
    ): array {
        if (! $uploadedFile instanceof UploadedFile) {
            return [null, null, null, null, null];
        }

        $folder = match ($statusType) {
            'image' => 'status-updates/images',
            'video' => 'status-updates/videos',
            'audio' => 'status-updates/audio',
            'music' => 'status-updates/music',
            default => 'status-updates/files',
        };

        $path = $uploadedFile->store($folder, 'public');

        return [
            'public',
            $path,
            $uploadedFile->getMimeType() ?: $uploadedFile->getClientMimeType(),
            $uploadedFile->getClientOriginalName(),
            $uploadedFile->getSize(),
        ];
    }

    /**
     * @return array<string, string>|null
     */
    private function musicMetaFromRequest(StoreStatusUpdateRequest $request): ?array
    {
        $title = trim((string) $request->input('music_title', ''));
        $artist = trim((string) $request->input('music_artist', ''));

        if ($title === '' && $artist === '') {
            return null;
        }

        return array_filter([
            'title' => $title,
            'artist' => $artist,
        ], static fn (string $value): bool => $value !== '');
    }

    /**
     * @return array<string, mixed>
     */
    private function transformStatus(StatusUpdate $status): array
    {
        $viewCount = $status->getAttribute('views_count');

        return [
            'id' => (int) $status->id,
            'author_type' => (string) $status->author_type,
            'author_name' => (string) $status->author_name,
            'status_type' => (string) $status->status_type,
            'text' => $status->text,
            'caption' => $status->caption,
            'background_color' => $status->background_color,
            'text_color' => $status->text_color,
            'font_style' => $status->font_style,
            'media_url' => $status->media_url,
            'media_mime_type' => $status->media_mime_type,
            'media_original_name' => $status->media_original_name,
            'media_size_bytes' => $status->media_size_bytes,
            'duration_seconds' => $status->duration_seconds,
            'music_meta' => $status->music_meta ?? [],
            'audience_scope' => $status->audience_scope,
            'posted_at' => $status->posted_at?->toIso8601String(),
            'expires_at' => $status->expires_at?->toIso8601String(),
            'is_active' => (bool) $status->is_active,
            'view_count' => is_numeric($viewCount)
                ? (int) $viewCount
                : ($status->relationLoaded('views') ? $status->views->count() : (int) $status->views()->count()),
        ];
    }
}
