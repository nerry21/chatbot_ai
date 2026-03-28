<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BookingRequest;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ConversationState;
use App\Services\Chatbot\ConversationInsightService;
use App\Services\Chatbot\ConversationReadService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class LiveChatController extends Controller
{
    private const FILTERS = [
        'all' => 'All',
        'unread' => 'Unread',
        'bot_active' => 'Bot Active',
        'human_takeover' => 'Human Takeover',
        'escalated' => 'Escalated',
        'closed' => 'Closed',
        'booking_in_progress' => 'Booking In Progress',
    ];

    public function __construct(
        private readonly ConversationReadService $readService,
        private readonly ConversationInsightService $insightService,
    ) {}

    public function index(Request $request): View
    {
        return view('admin.chatbot.live-chats.index', $this->workspaceData($request));
    }

    public function show(Request $request, Conversation $conversation): View
    {
        return view('admin.chatbot.live-chats.index', $this->workspaceData($request, $conversation));
    }

    public function pollList(Request $request): JsonResponse
    {
        $selectedConversationId = $request->integer('selected_conversation_id') ?: null;
        $data = $this->workspaceData($request, null, $selectedConversationId, false);

        return response()->json([
            'html' => view('admin.chatbot.live-chats.partials.list-pane', $data)->render(),
            'meta' => [
                'refreshed_at' => now()->format('H:i:s'),
                'unread_total' => (int) $data['conversations']->getCollection()->sum(
                    fn (Conversation $conversation): int => (int) ($conversation->unread_messages_count ?? 0),
                ),
            ],
        ]);
    }

    public function pollConversation(Request $request, Conversation $conversation): JsonResponse
    {
        $data = $this->workspaceData($request, $conversation, $conversation->id, true);

        return response()->json([
            'thread_html' => view('admin.chatbot.live-chats.partials.thread-pane', $data)->render(),
            'insight_html' => view('admin.chatbot.live-chats.partials.insight-pane', $data)->render(),
            'meta' => [
                'refreshed_at' => now()->format('H:i:s'),
                'selected_conversation_id' => $conversation->id,
            ],
        ]);
    }

    public function markRead(Request $request, Conversation $conversation): JsonResponse
    {
        if (auth()->check()) {
            $this->readService->markAsRead($conversation, (int) auth()->id());
        }

        return response()->json([
            'ok' => true,
            'conversation_id' => $conversation->id,
            'unread_count' => 0,
            'marked_at' => now()->format('H:i:s'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function workspaceData(
        Request $request,
        ?Conversation $selectedConversation = null,
        ?int $selectedConversationId = null,
        bool $markRead = true,
    ): array {
        $scope = $request->string('scope')->toString();
        $scope = array_key_exists($scope, self::FILTERS) ? $scope : 'all';

        $search = trim((string) $request->input('search', ''));
        $selectedConversationId ??= $selectedConversation?->id;

        $conversations = $this->conversationListQuery($scope, $search)
            ->paginate(18)
            ->withQueryString();

        $selectedConversation = $this->resolveSelectedConversation(
            conversations: $conversations,
            selectedConversation: $selectedConversation,
            selectedConversationId: $selectedConversationId,
        );

        if ($selectedConversation !== null && $markRead && auth()->check()) {
            $this->readService->markAsRead($selectedConversation, (int) auth()->id());
        }

        $conversationDetail = $selectedConversation !== null
            ? $this->loadConversationDetail($selectedConversation)
            : null;

        $messageGroups = $conversationDetail?->messages
            ? $conversationDetail->messages->groupBy(
                fn (ConversationMessage $message): string => $message->sent_at?->format('d M Y') ?? 'Tanpa tanggal',
            )
            : collect();

        $conversationInsight = $conversationDetail !== null
            ? $this->insightService->forConversation($conversationDetail)
            : null;

        return [
            'scope' => $scope,
            'search' => $search,
            'filters' => collect(self::FILTERS),
            'conversations' => $conversations,
            'selectedConversation' => $conversationDetail,
            'selectedConversationId' => $conversationDetail?->id ?? $selectedConversationId,
            'messageGroups' => $messageGroups,
            'stateSummary' => $conversationDetail !== null
                ? $this->buildStateSummary($conversationDetail->states)
                : collect(),
            'collectedSlots' => $conversationInsight['slot_summary'] ?? collect(),
            'internalNotes' => $conversationInsight['internal_notes'] ?? new EloquentCollection(),
            'conversationTags' => $conversationInsight['conversation_tags'] ?? new EloquentCollection(),
            'customerTags' => $conversationInsight['customer_tags'] ?? new EloquentCollection(),
            'auditTrail' => $conversationInsight['audit_trail'] ?? new EloquentCollection(),
            'latestEscalation' => $conversationDetail?->escalations->first(),
            'lastInbound' => $conversationDetail?->messages->first(
                fn (ConversationMessage $message): bool => $this->messageDirection($message) === 'inbound',
            ),
            'lastOutbound' => $conversationDetail?->messages->first(
                fn (ConversationMessage $message): bool => $this->messageDirection($message) === 'outbound'
                    && $this->messageSenderType($message) !== 'system',
            ),
            'lastUpdatedAt' => now()->format('H:i:s'),
        ];
    }

    private function conversationListQuery(string $scope, string $search): Builder
    {
        $userId = (int) (auth()->id() ?? 0);

        $query = Conversation::query()
            ->with(['customer', 'assignedAdmin'])
            ->select('conversations.*')
            ->selectSub($this->latestMessagePreviewSubquery(), 'last_message_preview')
            ->selectSub($this->latestMessageSenderSubquery(), 'last_message_sender_type')
            ->selectSub($this->readService->unreadCountSubquery($userId), 'unread_messages_count')
            ->latest('last_message_at')
            ->latest('id');

        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search): void {
                $builder
                    ->whereHas('customer', function (Builder $customerQuery) use ($search): void {
                        $customerQuery->where('name', 'like', "%{$search}%")
                            ->orWhere('phone_e164', 'like', "%{$search}%");
                    })
                    ->orWhere('current_intent', 'like', "%{$search}%")
                    ->orWhereExists(function ($sub) use ($search): void {
                        $sub->selectRaw('1')
                            ->from('conversation_messages as search_messages')
                            ->whereColumn('search_messages.conversation_id', 'conversations.id')
                            ->where('search_messages.message_text', 'like', "%{$search}%");
                    });
            });
        }

        $this->applyScope($query, $scope, $userId);

        return $query;
    }

    private function applyScope(Builder $query, string $scope, int $userId): void
    {
        match ($scope) {
            'unread' => $this->readService->applyUnreadFilter($query, $userId),
            'bot_active' => $query
                ->where(function (Builder $builder): void {
                    $builder->whereNull('handoff_mode')
                        ->orWhere('handoff_mode', 'bot');
                })
                ->where(function (Builder $builder): void {
                    $builder->whereNull('bot_paused')
                        ->orWhere('bot_paused', false);
                })
                ->whereNotIn('status', ['closed', 'archived']),
            'human_takeover' => $query->humanTakeoverActive(),
            'escalated' => $query->where(function (Builder $builder): void {
                $builder->where('status', 'escalated')
                    ->orWhere('needs_human', true)
                    ->orWhere('bot_paused', true);
            })->where(function (Builder $builder): void {
                $builder->whereNull('assigned_admin_id')
                    ->orWhere('handoff_mode', '!=', 'admin');
            }),
            'closed' => $query->whereIn('status', ['closed', 'archived']),
            'booking_in_progress' => $query->whereHas('bookingRequests', fn (Builder $builder) => $builder->active()),
            default => null,
        };
    }

    private function resolveSelectedConversation(
        LengthAwarePaginator $conversations,
        ?Conversation $selectedConversation = null,
        ?int $selectedConversationId = null,
    ): ?Conversation {
        if ($selectedConversation !== null) {
            return $selectedConversation;
        }

        if ($selectedConversationId !== null) {
            return Conversation::query()->find($selectedConversationId);
        }

        /** @var Conversation|null $firstConversation */
        $firstConversation = $conversations->getCollection()->first();

        return $firstConversation;
    }

    private function loadConversationDetail(Conversation $conversation): Conversation
    {
        $conversation->load([
            'customer.tags',
            'assignedAdmin',
            'handoffAdmin',
            'states' => fn ($query) => $query->active()->latest('updated_at')->limit(20),
            'bookingRequests' => fn ($query) => $query->latest()->limit(3),
            'escalations' => fn ($query) => $query->latest()->limit(5),
        ]);

        $messages = $conversation->messages()
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->limit(120)
            ->get()
            ->sortByDesc(fn (ConversationMessage $message) => $message->sent_at?->timestamp ?? $message->id)
            ->values();

        $conversation->setRelation('messages', $messages);

        return $conversation;
    }

    /**
     * @param  Collection<int, ConversationState>  $states
     * @return Collection<int, array{key: string, value: string}>
     */
    private function buildStateSummary(Collection $states): Collection
    {
        return $states->map(function (ConversationState $state): array {
            return [
                'key' => $state->state_key,
                'value' => $this->stringifyValue($state->state_value),
            ];
        });
    }

    private function latestMessagePreviewSubquery(): Builder
    {
        return ConversationMessage::query()
            ->select(DB::raw('COALESCE(NULLIF(message_text, ""), "[non-text]")'))
            ->whereColumn('conversation_id', 'conversations.id')
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->limit(1);
    }

    private function latestMessageSenderSubquery(): Builder
    {
        return ConversationMessage::query()
            ->select('sender_type')
            ->whereColumn('conversation_id', 'conversations.id')
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->limit(1);
    }

    private function stringifyValue(mixed $value): string
    {
        if (is_array($value)) {
            return collect($value)
                ->filter(fn (mixed $item): bool => filled($item))
                ->map(fn (mixed $item): string => is_scalar($item) ? (string) $item : json_encode($item))
                ->implode(', ');
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('d M Y H:i');
        }

        if (is_bool($value)) {
            return $value ? 'Ya' : 'Tidak';
        }

        if ($value === null || $value === '') {
            return '-';
        }

        return (string) $value;
    }

    private function messageDirection(ConversationMessage $message): string
    {
        return is_string($message->direction)
            ? $message->direction
            : (string) $message->direction?->value;
    }

    private function messageSenderType(ConversationMessage $message): string
    {
        return is_string($message->sender_type)
            ? $message->sender_type
            : (string) $message->sender_type?->value;
    }
}
