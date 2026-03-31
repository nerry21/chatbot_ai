<?php

namespace App\Services\Chatbot;

use App\Enums\ConversationChannel;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ConversationState;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AdminConversationWorkspaceService
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

    private const CHANNELS = [
        'all' => 'All Channels',
        'whatsapp' => 'WhatsApp',
        'mobile_live_chat' => 'Mobile Live Chat',
    ];

    private const SORTS = [
        'last_message_at' => 'Last Activity',
        'started_at' => 'Started At',
        'created_at' => 'Created At',
        'updated_at' => 'Updated At',
    ];

    private const SORT_COLUMNS = [
        'last_message_at' => 'conversations.last_message_at',
        'started_at' => 'conversations.started_at',
        'created_at' => 'conversations.created_at',
        'updated_at' => 'conversations.updated_at',
    ];

    private const SORT_DIRECTIONS = [
        'desc' => 'Descending',
        'asc' => 'Ascending',
    ];

    public function __construct(
        private readonly ConversationReadService $readService,
        private readonly ConversationInsightService $insightService,
        private readonly \App\Services\Mobile\MobileConversationService $mobileConversationService,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function workspaceData(
        int $userId,
        array $filters = [],
        ?Conversation $selectedConversation = null,
        ?int $selectedConversationId = null,
        bool $markRead = false,
    ): array {
        $listData = $this->listData($userId, $filters);
        $selectedConversationId ??= $selectedConversation?->id ?? $listData['selectedConversationId'];

        $selectedConversation = $this->resolveSelectedConversation(
            conversations: $listData['conversations'],
            selectedConversation: $selectedConversation,
            selectedConversationId: $selectedConversationId,
        );

        $detail = $selectedConversation !== null
            ? $this->conversationDetailData($selectedConversation, $userId, $markRead)
            : $this->emptyDetailData();

        return array_merge($listData, [
            'selectedConversation' => $detail['selectedConversation'],
            'selectedConversationId' => $detail['selectedConversation']?->id ?? $selectedConversationId,
            'messages' => $detail['messages'],
            'messageGroups' => $detail['messageGroups'],
            'threadGroups' => $detail['threadGroups'],
            'stateSummary' => $detail['stateSummary'],
            'collectedSlots' => $detail['collectedSlots'],
            'internalNotes' => $detail['internalNotes'],
            'conversationTags' => $detail['conversationTags'],
            'customerTags' => $detail['customerTags'],
            'auditTrail' => $detail['auditTrail'],
            'latestEscalation' => $detail['latestEscalation'],
            'lastInbound' => $detail['lastInbound'],
            'lastOutbound' => $detail['lastOutbound'],
            'lastUpdatedAt' => $detail['lastUpdatedAt'],
            'insightPane' => $detail['insightPane'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function listData(int $userId, array $filters = []): array
    {
        $normalized = $this->normalizeFilters($filters);

        $rawConversations = $this->conversationListQuery(
            scope: $normalized['scope'],
            search: $normalized['search'],
            channel: $normalized['channel'],
            userId: $userId,
            sortBy: $normalized['sort_by'],
            sortDir: $normalized['sort_dir'],
        )->get();

        $aggregated = $this->aggregateConversationCollection($rawConversations, $userId);
        $conversations = $this->paginateCollection(
            items: $aggregated,
            perPage: $normalized['per_page'],
            page: request()->integer('page', 1),
        );

        return [
            'scope' => $normalized['scope'],
            'channel' => $normalized['channel'],
            'search' => $normalized['search'],
            'sortBy' => $normalized['sort_by'],
            'sortDir' => $normalized['sort_dir'],
            'filters' => collect(self::FILTERS),
            'channels' => collect(self::CHANNELS),
            'filtersMeta' => $this->filtersMeta(
                scope: $normalized['scope'],
                channel: $normalized['channel'],
                search: $normalized['search'],
                perPage: $normalized['per_page'],
                sortBy: $normalized['sort_by'],
                sortDir: $normalized['sort_dir'],
            ),
            'summaryCounts' => $this->summaryCounts(
                userId: $userId,
                search: $normalized['search'],
                channel: $normalized['channel'],
                visibleUnreadTotal: (int) $conversations->getCollection()->sum(
                    fn (Conversation $conversation): int => (int) ($conversation->unread_messages_count ?? 0),
                ),
            ),
            'conversations' => $conversations,
            'pagination' => $this->paginationMeta($conversations),
            'selectedConversationId' => $this->resolveSelectedConversationIdFromCollection(
                preferredConversationId: $normalized['selected_conversation_id'],
                listedConversations: $conversations->getCollection(),
            ),
            'lastUpdatedAt' => now()->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function conversationDetailData(
        Conversation $conversation,
        int $userId,
        bool $markRead = false,
    ): array {
        $conversation = $this->loadConversationDetail($conversation, $userId);

        if ($markRead) {
            $this->markConversationGroupAsRead($conversation, $userId);
            $conversation->setAttribute('unread_messages_count', 0);
            $conversation->last_read_at_admin = now();
        }

        $messageGroups = $this->groupedMessagesByDate($conversation->messages);
        $conversationInsight = $this->insightService->forConversation($conversation);
        $stateSummary = $this->buildStateSummary($conversation->states);
        $latestEscalation = $conversation->escalations->first();
        $lastInbound = $conversation->messages->first(
            fn (ConversationMessage $message): bool => $this->messageDirection($message) === 'inbound',
        );
        $lastOutbound = $conversation->messages->first(
            fn (ConversationMessage $message): bool => $this->messageDirection($message) === 'outbound'
                && $this->messageSenderType($message) !== 'system',
        );

        return [
            'selectedConversation' => $conversation,
            'messages' => $conversation->messages,
            'messageGroups' => $messageGroups,
            'threadGroups' => $this->threadGroups($messageGroups),
            'stateSummary' => $stateSummary,
            'collectedSlots' => $conversationInsight['slot_summary'] ?? collect(),
            'internalNotes' => $conversationInsight['internal_notes'] ?? collect(),
            'conversationTags' => $conversationInsight['conversation_tags'] ?? collect(),
            'customerTags' => $conversationInsight['customer_tags'] ?? collect(),
            'auditTrail' => $conversationInsight['audit_trail'] ?? collect(),
            'latestEscalation' => $latestEscalation,
            'lastInbound' => $lastInbound,
            'lastOutbound' => $lastOutbound,
            'lastUpdatedAt' => now()->toIso8601String(),
            'insightPane' => [
                'conversation' => $conversation,
                'conversation_tags' => $conversationInsight['conversation_tags'] ?? collect(),
                'customer_tags' => $conversationInsight['customer_tags'] ?? collect(),
                'internal_notes' => $conversationInsight['internal_notes'] ?? collect(),
                'audit_trail' => $conversationInsight['audit_trail'] ?? collect(),
                'booking_summary' => $conversationInsight['slot_summary'] ?? collect(),
                'state_summary' => $stateSummary,
                'latest_escalation' => $latestEscalation,
                'last_inbound' => $lastInbound,
                'last_outbound' => $lastOutbound,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function pollConversationData(
        Conversation $conversation,
        int $userId,
        ?int $afterMessageId = null,
    ): array {
        $selectedConversation = $this->loadConversationDetail($conversation, $userId);
        $groupConversationIds = $this->conversationGroupIds($selectedConversation);

        $messagesQuery = ConversationMessage::query()
            ->with('senderUser')
            ->whereIn('conversation_id', $groupConversationIds);

        if ($afterMessageId !== null) {
            $messages = $messagesQuery
                ->where('id', '>', $afterMessageId)
                ->orderBy('id')
                ->limit((int) config('chatbot.admin_mobile.max_messages_per_fetch', 120))
                ->get();
        } else {
            $messages = $messagesQuery
                ->orderByDesc('sent_at')
                ->orderByDesc('id')
                ->limit((int) config('chatbot.admin_mobile.max_messages_per_fetch', 120))
                ->get()
                ->sortBy('id')
                ->values();
        }

        return [
            'selectedConversation' => $selectedConversation,
            'messages' => $messages,
            'messageGroups' => $this->groupedMessagesByDate($messages),
            'threadGroups' => $this->threadGroups($this->groupedMessagesByDate($messages)),
            'latestMessageId' => ConversationMessage::query()->whereIn('conversation_id', $groupConversationIds)->max('id'),
            'deltaCount' => $messages->count(),
            'unreadCount' => $this->groupUnreadCount($groupConversationIds, $userId),
            'lastUpdatedAt' => now()->toIso8601String(),
            'pollIntervalMs' => (int) config('chatbot.admin_mobile.poll_interval_ms', 3000),
        ];
    }

    /**
     * @return Collection<int, array{value: string, label: string}>
     */
    public function filterOptions(): Collection
    {
        return collect(self::FILTERS)
            ->map(fn (string $label, string $value): array => [
                'value' => $value,
                'label' => $label,
            ])
            ->values();
    }

    /**
     * @return Collection<int, array{value: string, label: string}>
     */
    public function channelOptions(): Collection
    {
        return collect(self::CHANNELS)
            ->map(fn (string $label, string $value): array => [
                'value' => $value,
                'label' => $label,
            ])
            ->values();
    }

    /**
     * @return Collection<int, array{value: string, label: string}>
     */
    public function sortOptions(): Collection
    {
        return collect(self::SORTS)
            ->map(fn (string $label, string $value): array => [
                'value' => $value,
                'label' => $label,
            ])
            ->values();
    }

    /**
     * @return Collection<int, array{value: string, label: string}>
     */
    public function sortDirectionOptions(): Collection
    {
        return collect(self::SORT_DIRECTIONS)
            ->map(fn (string $label, string $value): array => [
                'value' => $value,
                'label' => $label,
            ])
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    public function filtersMeta(
        string $scope = 'all',
        string $channel = 'all',
        string $search = '',
        ?int $perPage = null,
        string $sortBy = 'last_message_at',
        string $sortDir = 'desc',
    ): array {
        return [
            'scope' => $this->normalizeScope($scope),
            'channel' => $this->normalizeChannel($channel),
            'search' => trim($search),
            'per_page' => $perPage ?? (int) config('chatbot.admin_mobile.default_per_page', 18),
            'sort_by' => $this->normalizeSortBy($sortBy),
            'sort_dir' => $this->normalizeSortDirection($sortDir),
            'available_scopes' => $this->filterOptions(),
            'available_channels' => $this->channelOptions(),
            'available_sorts' => $this->sortOptions(),
            'available_sort_directions' => $this->sortDirectionOptions(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function summaryCounts(
        int $userId,
        string $search = '',
        string $channel = 'all',
        int $visibleUnreadTotal = 0,
    ): array {
        $query = $this->conversationSummaryBaseQuery($search, $channel);
        $scopeTotals = [];

        foreach (array_keys(self::FILTERS) as $scope) {
            $scopeQuery = clone $query;

            if ($scope !== 'all') {
                $this->applyScope($scopeQuery, $scope, $userId);
            }

            $scopeTotals[$scope] = $this->aggregateConversationCollection(
                $scopeQuery
                    ->with(['customer', 'assignedAdmin'])
                    ->select('conversations.*')
                    ->selectSub($this->latestMessagePreviewSubquery(), 'last_message_preview')
                    ->selectSub($this->latestMessageSenderSubquery(), 'last_message_sender_type')
                    ->selectSub($this->readService->unreadCountSubquery($userId), 'unread_messages_count')
                    ->orderByDesc('conversations.last_message_at')
                    ->orderByDesc('conversations.id')
                    ->get(),
                $userId,
            )->count();
        }

        return [
            'scope_totals' => $scopeTotals,
            'total_conversations' => (int) ($scopeTotals['all'] ?? 0),
            'visible_unread_total' => $visibleUnreadTotal,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{
     *     scope: string,
     *     channel: string,
     *     search: string,
     *     per_page: int,
     *     selected_conversation_id: int|null,
     *     sort_by: string,
     *     sort_dir: string
     * }
     */
    private function normalizeFilters(array $filters): array
    {
        return [
            'scope' => $this->normalizeScope((string) ($filters['scope'] ?? 'all')),
            'channel' => $this->normalizeChannel((string) ($filters['channel'] ?? 'all')),
            'search' => trim((string) ($filters['search'] ?? '')),
            'sort_by' => $this->normalizeSortBy((string) ($filters['sort_by'] ?? 'last_message_at')),
            'sort_dir' => $this->normalizeSortDirection((string) ($filters['sort_dir'] ?? 'desc')),
            'per_page' => max(
                1,
                min(
                    (int) config('chatbot.admin_mobile.max_per_page', 50),
                    (int) ($filters['per_page'] ?? config('chatbot.admin_mobile.default_per_page', 18)),
                ),
            ),
            'selected_conversation_id' => isset($filters['selected_conversation_id'])
                ? (int) $filters['selected_conversation_id']
                : null,
        ];
    }

    private function normalizeScope(string $scope): string
    {
        return array_key_exists($scope, self::FILTERS) ? $scope : 'all';
    }

    private function normalizeChannel(string $channel): string
    {
        return array_key_exists($channel, self::CHANNELS) ? $channel : 'all';
    }

    private function normalizeSortBy(string $sortBy): string
    {
        return array_key_exists($sortBy, self::SORTS) ? $sortBy : 'last_message_at';
    }

    private function normalizeSortDirection(string $sortDir): string
    {
        $normalized = strtolower($sortDir);

        return array_key_exists($normalized, self::SORT_DIRECTIONS) ? $normalized : 'desc';
    }

    private function conversationListQuery(
        string $scope,
        string $search,
        string $channel,
        int $userId,
        string $sortBy,
        string $sortDir,
    ): Builder {
        $sortColumn = self::SORT_COLUMNS[$sortBy] ?? self::SORT_COLUMNS['last_message_at'];
        $query = Conversation::query()
            ->with(['customer', 'assignedAdmin'])
            ->select('conversations.*')
            ->selectSub($this->latestMessagePreviewSubquery(), 'last_message_preview')
            ->selectSub($this->latestMessageSenderSubquery(), 'last_message_sender_type')
            ->selectSub($this->readService->unreadCountSubquery($userId), 'unread_messages_count')
            ->orderBy($sortColumn, $sortDir)
            ->orderBy('conversations.id', $sortDir);

        if ($channel !== 'all') {
            $query->where('conversations.channel', $channel);
        }

        $this->applySearch($query, $search);
        $this->applyScope($query, $scope, $userId);

        return $query;
    }

    private function conversationSummaryBaseQuery(string $search, string $channel): Builder
    {
        $query = Conversation::query()
            ->select('conversations.id');

        if ($channel !== 'all') {
            $query->where('conversations.channel', $channel);
        }

        $this->applySearch($query, $search);

        return $query;
    }

    private function applySearch(Builder $query, string $search): void
    {
        if ($search === '') {
            return;
        }

        $query->where(function (Builder $builder) use ($search): void {
            $builder
                ->whereHas('customer', function (Builder $customerQuery) use ($search): void {
                    $customerQuery->where('name', 'like', "%{$search}%")
                        ->orWhere('phone_e164', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('mobile_user_id', 'like', "%{$search}%");
                })
                ->orWhere('channel', 'like', "%{$search}%")
                ->orWhere('source_app', 'like', "%{$search}%")
                ->orWhere('current_intent', 'like', "%{$search}%")
                ->orWhereExists(function ($sub) use ($search): void {
                    $sub->selectRaw('1')
                        ->from('conversation_messages as search_messages')
                        ->whereColumn('search_messages.conversation_id', 'conversations.id')
                        ->where('search_messages.message_text', 'like', "%{$search}%");
                });
        });
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
            $resolvedId = $this->representativeConversationIdForConversation($selectedConversation)
                ?? $selectedConversation->id;

            /** @var Conversation|null $listedMatch */
            $listedMatch = $conversations->getCollection()->firstWhere('id', $resolvedId);

            return $listedMatch ?? Conversation::query()->find($resolvedId);
        }

        if ($selectedConversationId !== null) {
            /** @var Conversation|null $listedConversation */
            $listedConversation = $conversations->getCollection()->firstWhere('id', $selectedConversationId);

            if ($listedConversation !== null) {
                return $listedConversation;
            }

            $resolvedId = $this->representativeConversationIdByConversationId($selectedConversationId) ?? $selectedConversationId;

            /** @var Conversation|null $resolvedListedConversation */
            $resolvedListedConversation = $conversations->getCollection()->firstWhere('id', $resolvedId);

            return $resolvedListedConversation ?? Conversation::query()->find($resolvedId);
        }

        /** @var Conversation|null $firstConversation */
        $firstConversation = $conversations->getCollection()->first();

        return $firstConversation;
    }

    private function loadConversationDetail(Conversation $conversation, int $userId): Conversation
    {
        $conversation = Conversation::query()
            ->with([
                'customer' => fn ($query) => $query->with([
                    'tags' => fn ($tagQuery) => $tagQuery->latest('created_at'),
                ]),
                'assignedAdmin',
                'handoffAdmin',
                'tags' => fn ($query) => $query->latest('created_at'),
                'states' => fn ($query) => $query->active()->latest('updated_at')->limit(20),
                'bookingRequests' => fn ($query) => $query->latest()->limit(3),
                'escalations' => fn ($query) => $query->latest()->limit(5),
            ])
            ->findOrFail($conversation->id);

        $groupConversationIds = $this->conversationGroupIds($conversation);

        $messages = ConversationMessage::query()
            ->with('senderUser')
            ->whereIn('conversation_id', $groupConversationIds)
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->limit((int) config('chatbot.admin_mobile.max_messages_per_fetch', 120))
            ->get()
            ->sortByDesc(fn (ConversationMessage $message) => $message->sent_at?->timestamp ?? $message->id)
            ->values();

        $latestMessage = $messages->first();

        $conversation->setRelation('messages', $messages);
        $conversation->setAttribute('unread_messages_count', $this->groupUnreadCount($groupConversationIds, $userId));
        $conversation->setAttribute(
            'last_message_preview',
            (string) ($latestMessage?->message_text ?? $conversation->last_message_preview ?? ''),
        );
        $conversation->setAttribute(
            'last_message_sender_type',
            (string) ($latestMessage !== null ? $this->messageSenderType($latestMessage) : ($conversation->last_message_sender_type ?? '')),
        );
        $conversation->setAttribute('merged_conversation_ids', $groupConversationIds->values()->all());
        $conversation->setAttribute('merged_conversation_count', $groupConversationIds->count());
        $conversation->setAttribute('merge_key', $this->conversationMergeKey($conversation));

        return $conversation;
    }

    /**
     * @param  Collection<int, ConversationMessage>  $messages
     * @return Collection<string, Collection<int, ConversationMessage>>
     */
    private function groupedMessagesByDate(Collection $messages): Collection
    {
        return $messages->groupBy(
            fn (ConversationMessage $message): string => $message->sent_at?->format('d M Y') ?? 'Tanpa tanggal',
        );
    }

    /**
     * @param  Collection<string, Collection<int, ConversationMessage>>  $messageGroups
     * @return Collection<int, array{date_label: string, messages: Collection<int, ConversationMessage>}>
     */
    private function threadGroups(Collection $messageGroups): Collection
    {
        return $messageGroups
            ->map(fn (Collection $group, string $dateLabel): array => [
                'date_label' => $dateLabel,
                'messages' => $group->values(),
            ])
            ->values();
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

    /**
     * @return array<string, mixed>
     */
    private function paginationMeta(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'last_page' => $paginator->lastPage(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
            'total' => $paginator->total(),
            'has_more_pages' => $paginator->hasMorePages(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyDetailData(): array
    {
        return [
            'selectedConversation' => null,
            'messages' => collect(),
            'messageGroups' => collect(),
            'threadGroups' => collect(),
            'stateSummary' => collect(),
            'collectedSlots' => collect(),
            'internalNotes' => collect(),
            'conversationTags' => collect(),
            'customerTags' => collect(),
            'auditTrail' => collect(),
            'latestEscalation' => null,
            'lastInbound' => null,
            'lastOutbound' => null,
            'lastUpdatedAt' => now()->toIso8601String(),
            'insightPane' => null,
        ];
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

    private function messageDirection(?ConversationMessage $message): string
    {
        if ($message === null) {
            return '';
        }

        return is_string($message->direction)
            ? $message->direction
            : (string) $message->direction?->value;
    }

    private function messageSenderType(?ConversationMessage $message): string
    {
        if ($message === null) {
            return '';
        }

        return is_string($message->sender_type)
            ? $message->sender_type
            : (string) $message->sender_type?->value;
    }

    /**
     * @param  Collection<int, Conversation>  $conversations
     * @return Collection<int, Conversation>
     */
    private function aggregateConversationCollection(Collection $conversations, int $userId): Collection
    {
        return $conversations
            ->groupBy(fn (Conversation $conversation): string => $this->conversationMergeKey($conversation))
            ->map(function (Collection $group) use ($userId): Conversation {
                /** @var Conversation $representative */
                $representative = $group
                    ->sortByDesc(fn (Conversation $conversation): int => $conversation->last_message_at?->timestamp ?? $conversation->id)
                    ->first();

                $groupIds = $group->pluck('id')->filter()->map(fn (mixed $id): int => (int) $id)->values();
                $representative->setAttribute('merged_conversation_ids', $groupIds->all());
                $representative->setAttribute('merged_conversation_count', $groupIds->count());
                $representative->setAttribute('merge_key', $this->conversationMergeKey($representative));
                $representative->setAttribute(
                    'unread_messages_count',
                    $this->groupUnreadCount($groupIds, $userId, $group),
                );

                $latestConversation = $group
                    ->sortByDesc(fn (Conversation $conversation): int => $conversation->last_message_at?->timestamp ?? $conversation->id)
                    ->first();

                if ($latestConversation !== null) {
                    $representative->last_message_at = $latestConversation->last_message_at;
                    $representative->setAttribute(
                        'last_message_preview',
                        (string) ($latestConversation->last_message_preview ?? $representative->last_message_preview ?? ''),
                    );
                    $representative->setAttribute(
                        'last_message_sender_type',
                        (string) ($latestConversation->last_message_sender_type ?? $representative->last_message_sender_type ?? ''),
                    );
                }

                return $representative;
            })
            ->sortByDesc(fn (Conversation $conversation): int => $conversation->last_message_at?->timestamp ?? $conversation->id)
            ->values();
    }

    private function conversationMergeKey(Conversation $conversation): string
    {
        $channel = (string) $conversation->channel;

        if ($channel !== ConversationChannel::WhatsApp->value) {
            return $channel.':conversation:'.$conversation->id;
        }

        $customer = $conversation->relationLoaded('customer') ? $conversation->customer : $conversation->customer()->first();
        $phone = trim((string) ($customer?->phone_e164 ?? ''));

        if ($phone !== '') {
            return $channel.':phone:'.mb_strtolower($phone, 'UTF-8');
        }

        return $channel.':customer:'.(string) $conversation->customer_id;
    }

    /**
     * @return Collection<int, int>
     */
    private function conversationGroupIds(Conversation $conversation): Collection
    {
        $existing = collect($conversation->getAttribute('merged_conversation_ids'))
            ->filter(fn (mixed $id): bool => is_numeric($id))
            ->map(fn (mixed $id): int => (int) $id)
            ->values();

        if ($existing->isNotEmpty()) {
            return $existing;
        }

        if ((string) $conversation->channel !== ConversationChannel::WhatsApp->value) {
            return collect([(int) $conversation->id]);
        }

        $phone = trim((string) ($conversation->customer?->phone_e164 ?? ''));

        if ($phone === '') {
            return collect([(int) $conversation->id]);
        }

        return Conversation::query()
            ->where('channel', ConversationChannel::WhatsApp->value)
            ->whereHas('customer', fn (Builder $query) => $query->where('phone_e164', $phone))
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values();
    }

    /**
     * @param  Collection<int, int>  $conversationIds
     * @param  Collection<int, Conversation>|null  $loadedConversations
     */
    private function groupUnreadCount(Collection $conversationIds, int $userId, ?Collection $loadedConversations = null): int
    {
        if ($conversationIds->isEmpty()) {
            return 0;
        }

        if ($loadedConversations !== null && $loadedConversations->isNotEmpty()) {
            return (int) $loadedConversations->sum(function (Conversation $conversation) use ($userId): int {
                $preloaded = $conversation->getAttribute('unread_messages_count');

                if (is_numeric($preloaded)) {
                    return (int) $preloaded;
                }

                return $this->readService->unreadCountForConversation($conversation, $userId);
            });
        }

        return Conversation::query()
            ->whereIn('id', $conversationIds)
            ->get()
            ->sum(fn (Conversation $conversation): int => $this->readService->unreadCountForConversation($conversation, $userId));
    }

    private function markConversationGroupAsRead(Conversation $conversation, int $userId): void
    {
        $groupConversations = Conversation::query()
            ->whereIn('id', $this->conversationGroupIds($conversation))
            ->get();

        foreach ($groupConversations as $groupConversation) {
            $this->readService->markAsRead($groupConversation, $userId);
            $this->mobileConversationService->touchAdminRead($groupConversation);
        }
    }

    private function representativeConversationIdForConversation(Conversation $conversation): ?int
    {
        if ((string) $conversation->channel !== ConversationChannel::WhatsApp->value) {
            return $conversation->id;
        }

        $phone = trim((string) ($conversation->customer?->phone_e164 ?? ''));

        if ($phone === '') {
            return $conversation->id;
        }

        return Conversation::query()
            ->where('channel', ConversationChannel::WhatsApp->value)
            ->whereHas('customer', fn (Builder $query) => $query->where('phone_e164', $phone))
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->value('id');
    }

    private function representativeConversationIdByConversationId(int $conversationId): ?int
    {
        $conversation = Conversation::query()->with('customer')->find($conversationId);

        if (! $conversation instanceof Conversation) {
            return null;
        }

        return $this->representativeConversationIdForConversation($conversation);
    }

    /**
     * @param  Collection<int, Conversation>  $listedConversations
     */
    private function resolveSelectedConversationIdFromCollection(?int $preferredConversationId, Collection $listedConversations): ?int
    {
        if ($preferredConversationId !== null) {
            $resolvedId = $this->representativeConversationIdByConversationId($preferredConversationId)
                ?? $preferredConversationId;

            if ($listedConversations->contains(fn (Conversation $conversation): bool => (int) $conversation->id === $resolvedId)) {
                return $resolvedId;
            }
        }

        /** @var Conversation|null $first */
        $first = $listedConversations->first();

        return $first?->id;
    }

    /**
     * @param  Collection<int, Conversation>  $items
     */
    private function paginateCollection(Collection $items, int $perPage, int $page): LengthAwarePaginator
    {
        $page = max($page, 1);
        $total = $items->count();
        $results = $items->slice(($page - 1) * $perPage, $perPage)->values();

        return new LengthAwarePaginator(
            $results,
            $total,
            $perPage,
            $page,
            [
                'path' => request()->url(),
                'query' => request()->query(),
            ],
        );
    }
}
