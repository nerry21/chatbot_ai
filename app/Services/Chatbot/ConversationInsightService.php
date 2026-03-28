<?php

namespace App\Services\Chatbot;

use App\Enums\AuditActionType;
use App\Enums\BookingStatus;
use App\Enums\ConversationStatus;
use App\Models\AdminNote;
use App\Models\AuditLog;
use App\Models\BookingRequest;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ConversationState;
use App\Models\Escalation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ConversationInsightService
{
    public function __construct(
        private readonly InternalNoteService $noteService,
    ) {}

    /**
     * @return array{
     *     slot_summary: Collection<int, array{label: string, value: string, palette: string|null}>,
     *     internal_notes: EloquentCollection<int, AdminNote>,
     *     conversation_tags: EloquentCollection<int, \App\Models\ConversationTag>,
     *     customer_tags: EloquentCollection<int, \App\Models\CustomerTag>,
     *     audit_trail: EloquentCollection<int, AuditLog>
     * }
     */
    public function forConversation(Conversation $conversation): array
    {
        $states = $conversation->relationLoaded('states')
            ? $conversation->states
            : $conversation->states()->active()->latest('updated_at')->get();
        $booking = $conversation->relationLoaded('bookingRequests')
            ? $conversation->bookingRequests->first()
            : $conversation->bookingRequests()->latest()->first();

        return [
            'slot_summary' => $this->buildSlotSummary($states, $booking),
            'internal_notes' => $this->noteService->recentForConversation($conversation, 8),
            'conversation_tags' => $conversation->tags()->latest('created_at')->get(),
            'customer_tags' => $conversation->customer?->tags()->latest('created_at')->get() ?? new EloquentCollection(),
            'audit_trail' => AuditLog::query()
                ->with('actor')
                ->forConversation($conversation->id)
                ->latest('created_at')
                ->limit(10)
                ->get(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function dashboard(): array
    {
        $topAdminRows = AuditLog::query()
            ->select('actor_user_id', DB::raw('COUNT(*) as action_count'))
            ->whereNotNull('actor_user_id')
            ->whereIn('action_type', [
                AuditActionType::ConversationTakeover->value,
                AuditActionType::ConversationRelease->value,
                AuditActionType::AdminReplySent->value,
                AuditActionType::ConversationMarkedEscalated->value,
                AuditActionType::ConversationClosed->value,
                AuditActionType::ConversationReopened->value,
            ])
            ->where('created_at', '>=', now()->subDays(30))
            ->groupBy('actor_user_id')
            ->orderByDesc('action_count')
            ->limit(5)
            ->get();

        $actorIds = $topAdminRows->pluck('actor_user_id')->filter()->map(fn ($id) => (int) $id)->all();
        $actorMap = User::query()->whereIn('id', $actorIds)->pluck('name', 'id');

        return [
            'top_admins' => $topAdminRows->map(fn ($row): array => [
                'name' => $actorMap[(int) $row->actor_user_id] ?? 'Unknown admin',
                'actions' => (int) $row->action_count,
            ]),
            'conversation_by_status' => [
                'bot_active' => Conversation::query()
                    ->where(function (Builder $builder): void {
                        $builder->whereNull('handoff_mode')
                            ->orWhere('handoff_mode', 'bot');
                    })
                    ->where(function (Builder $builder): void {
                        $builder->whereNull('bot_paused')
                            ->orWhere('bot_paused', false);
                    })
                    ->whereNotIn('status', [ConversationStatus::Closed->value, ConversationStatus::Archived->value])
                    ->count(),
                'human_takeover' => Conversation::humanTakeoverActive()->count(),
                'escalated' => Conversation::query()
                    ->where(function (Builder $builder): void {
                        $builder->where('status', ConversationStatus::Escalated->value)
                            ->orWhere('needs_human', true)
                            ->orWhere('bot_paused', true);
                    })
                    ->where(function (Builder $builder): void {
                        $builder->whereNull('assigned_admin_id')
                            ->orWhere('handoff_mode', '!=', 'admin');
                    })
                    ->count(),
                'closed' => Conversation::query()
                    ->whereIn('status', [ConversationStatus::Closed->value, ConversationStatus::Archived->value])
                    ->count(),
            ],
            'escalations_today' => [
                'total' => Escalation::query()->whereDate('created_at', today())->count(),
                'open' => Escalation::query()->whereDate('created_at', today())->where('status', 'open')->count(),
                'urgent' => Escalation::query()->whereDate('created_at', today())->where('priority', 'urgent')->count(),
            ],
            'booking_conversion' => $this->bookingConversion(),
            'failed_message_insight' => [
                'failed_24h' => ConversationMessage::query()
                    ->where('direction', 'outbound')
                    ->where('delivery_status', 'failed')
                    ->where('created_at', '>=', now()->subDay())
                    ->count(),
                'skipped_24h' => ConversationMessage::query()
                    ->where('direction', 'outbound')
                    ->where('delivery_status', 'skipped')
                    ->where('created_at', '>=', now()->subDay())
                    ->count(),
                'pending_stale' => ConversationMessage::query()
                    ->where('direction', 'outbound')
                    ->where('delivery_status', 'pending')
                    ->where('created_at', '<=', now()->subMinutes(10))
                    ->count(),
            ],
            'recent_admin_interventions' => AuditLog::query()
                ->with(['actor', 'conversation.customer'])
                ->whereIn('action_type', [
                    AuditActionType::ConversationTakeover->value,
                    AuditActionType::ConversationRelease->value,
                    AuditActionType::AdminReplySent->value,
                    AuditActionType::ConversationMarkedEscalated->value,
                    AuditActionType::ConversationClosed->value,
                    AuditActionType::ConversationReopened->value,
                    AuditActionType::InternalNoteCreated->value,
                ])
                ->latest('created_at')
                ->limit(8)
                ->get(),
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, ConversationState>  $states
     * @return Collection<int, array{label: string, value: string, palette: string|null}>
     */
    private function buildSlotSummary(Collection $states, ?BookingRequest $booking): Collection
    {
        $stateMap = $states->mapWithKeys(fn (ConversationState $state): array => [
            $state->state_key => [$state->state_value, $state->updated_at],
        ]);

        $reviewSent = (bool) data_get($stateMap->get('review_sent'), '0', false);
        $bookingConfirmed = (bool) data_get($stateMap->get('booking_confirmed'), '0', false);
        $finalConfirmationReceived = (bool) data_get($stateMap->get('final_confirmation_received'), '0', false);
        $adminForwarded = (bool) data_get($stateMap->get('admin_forwarded'), '0', false);

        $items = [
            ['label' => 'Nama', 'value' => $booking?->passenger_name ?? data_get($stateMap->get('passenger_name'), '0')],
            ['label' => 'Jumlah penumpang', 'value' => $booking?->passenger_count ?? data_get($stateMap->get('passenger_count'), '0')],
            ['label' => 'Tanggal', 'value' => $booking?->departure_date?->format('d M Y') ?? data_get($stateMap->get('travel_date'), '0')],
            ['label' => 'Jam', 'value' => $booking?->departure_time ?? data_get($stateMap->get('travel_time'), '0')],
            ['label' => 'Kursi', 'value' => $this->stringify($booking?->selected_seats ?? data_get($stateMap->get('selected_seats'), '0'))],
            ['label' => 'Lokasi jemput', 'value' => $booking?->pickup_location ?? data_get($stateMap->get('pickup_location'), '0')],
            ['label' => 'Alamat jemput', 'value' => $booking?->pickup_full_address ?? data_get($stateMap->get('pickup_full_address'), '0')],
            ['label' => 'Tujuan', 'value' => $booking?->destination ?? data_get($stateMap->get('destination'), '0')],
            ['label' => 'Alamat tujuan', 'value' => $booking?->destination_full_address ?? data_get($stateMap->get('destination_full_address'), '0')],
            ['label' => 'Payment method', 'value' => $booking?->payment_method ?? data_get($stateMap->get('payment_method'), '0')],
            [
                'label' => 'Review status',
                'value' => $reviewSent
                    ? ($finalConfirmationReceived ? 'Final confirmation received' : 'Review sent')
                    : 'Belum dikirim',
                'palette' => $reviewSent ? 'amber' : 'slate',
            ],
            [
                'label' => 'Confirmation status',
                'value' => $bookingConfirmed ? 'Confirmed' : 'Belum confirmed',
                'palette' => $bookingConfirmed ? 'green' : 'slate',
            ],
            [
                'label' => 'Forward to admin',
                'value' => $adminForwarded ? 'Sudah diteruskan' : 'Belum diteruskan',
                'palette' => $adminForwarded ? 'green' : 'slate',
            ],
        ];

        return collect($items)
            ->map(fn (array $item): array => [
                'label' => $item['label'],
                'value' => $this->stringify($item['value']),
                'palette' => $item['palette'] ?? null,
            ])
            ->filter(fn (array $item): bool => filled($item['value']) && $item['value'] !== '-')
            ->values();
    }

    /**
     * @return array<string, int|float>
     */
    private function bookingConversion(): array
    {
        $total = BookingRequest::query()->count();
        $converted = BookingRequest::query()
            ->whereIn('booking_status', [
                BookingStatus::Confirmed->value,
                BookingStatus::Paid->value,
                BookingStatus::Completed->value,
            ])
            ->count();

        return [
            'total' => $total,
            'converted' => $converted,
            'awaiting_confirmation' => BookingRequest::query()
                ->where('booking_status', BookingStatus::AwaitingConfirmation->value)
                ->count(),
            'draft' => BookingRequest::query()
                ->where('booking_status', BookingStatus::Draft->value)
                ->count(),
            'conversion_rate' => $total > 0 ? round(($converted / $total) * 100, 1) : 0.0,
        ];
    }

    private function stringify(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('d M Y H:i');
        }

        if (is_array($value)) {
            return collect($value)
                ->filter(fn (mixed $item): bool => filled($item))
                ->map(fn (mixed $item): string => (string) $item)
                ->implode(', ');
        }

        if (is_bool($value)) {
            return $value ? 'Ya' : 'Tidak';
        }

        if (! filled($value)) {
            return '-';
        }

        return (string) $value;
    }
}
