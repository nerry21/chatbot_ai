<?php

namespace App\Http\Controllers\Api\AdminMobile;

use App\Enums\MessageDirection;
use App\Enums\SenderType;
use App\Http\Controllers\Api\AdminMobile\Concerns\RespondsWithAdminMobileJson;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ConversationState;
use App\Services\Chatbot\BotAutomationToggleService;
use App\Services\Chatbot\ConversationInsightService;
use App\Services\Chatbot\ConversationReadService;
use App\Services\Mobile\MobileConversationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class WorkspaceController extends Controller
{
    use RespondsWithAdminMobileJson;

    private const FILTERS = [
        'all' => 'Semua',
        'unread' => 'Belum Dibaca',
        'bot_active' => 'Bot Active',
        'human_takeover' => 'Takeover',
        'escalated' => 'Escalated',
        'closed' => 'Closed',
        'booking_in_progress' => 'Booking Progress',
    ];

    private const CHANNELS = [
        'all' => 'Semua Channel',
        'whatsapp' => 'WhatsApp',
        'mobile_live_chat' => 'Live Chat',
    ];

    public function __construct(
        private readonly ConversationReadService $readService,
        private readonly ConversationInsightService $insightService,
        private readonly MobileConversationService $mobileConversationService,
        private readonly BotAutomationToggleService $botToggleService,
    ) {}

    public function workspace(Request $request): JsonResponse
    {
        $query = $this->queryFromRequest($request);
        $userId = (int) ($request->attributes->get('admin_mobile_user')?->id ?? 0);
        $paginator = $this->conversationListQuery($query['scope'], $query['search'], $query['channel'], $userId)
            ->paginate($query['per_page'], ['*'], 'page', $query['page'])
            ->withQueryString();

        $this->normalizePaginatorConversations($paginator, $userId);

        return $this->successResponse('Workspace admin mobile berhasil diambil.', [
            'workspace' => [
                'unread_total' => (int) $paginator->getCollection()->sum(fn (Conversation $conversation) => (int) ($conversation->unread_messages_count ?? 0)),
                'active_conversations' => (int) $paginator->total(),
                'filters' => [
                    'scopes' => $this->filterOptions($query['search'], $query['channel'], $userId),
                    'channels' => $this->channelOptions($query['search'], $query['scope'], $userId),
                ],
            ],
            'summary' => $this->dashboardSummaryPayload(),
        ]);
    }

    public function conversations(Request $request): JsonResponse
    {
        $query = $this->queryFromRequest($request);
        $userId = (int) ($request->attributes->get('admin_mobile_user')?->id ?? 0);
        $paginator = $this->conversationListQuery($query['scope'], $query['search'], $query['channel'], $userId)
            ->paginate($query['per_page'], ['*'], 'page', $query['page'])
            ->withQueryString();

        $this->normalizePaginatorConversations($paginator, $userId);

        return $this->successResponse('Daftar percakapan admin mobile berhasil diambil.', [
            'conversations' => $paginator->getCollection()->map(fn (Conversation $conversation): array => $this->conversationListItem($conversation, $userId))->values()->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'selected_conversation_id' => $request->integer('selected_conversation_id') ?: null,
            ],
        ]);
    }

    public function detail(Request $request, Conversation $conversation): JsonResponse
    {
        $userId = (int) ($request->attributes->get('admin_mobile_user')?->id ?? 0);
        $conversation = $this->loadConversationDetail($conversation);
        $insight = $this->insightService->forConversation($conversation);

        return $this->successResponse('Detail percakapan admin mobile berhasil diambil.', [
            'conversation' => $this->conversationDetailPayload($conversation, $userId),
            'insight' => $this->insightPayload($conversation, $insight),
        ]);
    }

    public function messages(Request $request, Conversation $conversation): JsonResponse
    {
        $userId = (int) ($request->attributes->get('admin_mobile_user')?->id ?? 0);
        $conversation = $this->loadConversationDetail($conversation);
        $insight = $this->insightService->forConversation($conversation);

        return $this->successResponse('Thread percakapan admin mobile berhasil diambil.', [
            'conversation' => $this->conversationDetailPayload($conversation, $userId),
            'messages' => $conversation->messages->map(fn (ConversationMessage $message): array => $this->threadMessagePayload($message))->values()->all(),
            'insight' => $this->insightPayload($conversation, $insight),
        ]);
    }

    public function pollConversation(Request $request, Conversation $conversation): JsonResponse
    {
        $userId = (int) ($request->attributes->get('admin_mobile_user')?->id ?? 0);
        $conversation = $this->loadConversationDetail($conversation);
        $insight = $this->insightService->forConversation($conversation);

        return $this->successResponse('Polling percakapan admin mobile berhasil.', [
            'conversation' => $this->conversationDetailPayload($conversation, $userId),
            'messages' => $conversation->messages->map(fn (ConversationMessage $message): array => $this->threadMessagePayload($message))->values()->all(),
            'insight' => $this->insightPayload($conversation, $insight),
            'meta' => [
                'selected_conversation_id' => $conversation->id,
                'unread_count' => $this->readService->unreadCountForConversation($conversation, $userId),
                'server_time' => now()->toIso8601String(),
            ],
        ]);
    }

    public function pollList(Request $request): JsonResponse
    {
        $query = $this->queryFromRequest($request);
        $userId = (int) ($request->attributes->get('admin_mobile_user')?->id ?? 0);
        $paginator = $this->conversationListQuery($query['scope'], $query['search'], $query['channel'], $userId)
            ->paginate($query['per_page'], ['*'], 'page', $query['page'])
            ->withQueryString();

        $this->normalizePaginatorConversations($paginator, $userId);

        return $this->successResponse('Polling list admin mobile berhasil.', [
            'conversations' => $paginator->getCollection()->map(fn (Conversation $conversation): array => $this->conversationListItem($conversation, $userId))->values()->all(),
            'filters' => [
                'scopes' => $this->filterOptions($query['search'], $query['channel'], $userId),
                'channels' => $this->channelOptions($query['search'], $query['scope'], $userId),
            ],
            'summary' => [
                'unread_total' => (int) $paginator->getCollection()->sum(fn (Conversation $conversation) => (int) ($conversation->unread_messages_count ?? 0)),
                'active_conversations' => (int) $paginator->total(),
            ],
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'selected_conversation_id' => $request->integer('selected_conversation_id') ?: null,
                'refreshed_at' => now()->toIso8601String(),
            ],
        ]);
    }

    public function dashboardSummary(): JsonResponse
    {
        return $this->successResponse('Ringkasan dashboard admin mobile berhasil diambil.', [
            'summary' => $this->dashboardSummaryPayload(),
        ]);
    }

    public function metaFilters(Request $request): JsonResponse
    {
        $query = $this->queryFromRequest($request);
        $userId = (int) ($request->attributes->get('admin_mobile_user')?->id ?? 0);

        return $this->successResponse('Filter metadata admin mobile berhasil diambil.', [
            'filters' => [
                'scopes' => $this->filterOptions($query['search'], $query['channel'], $userId),
                'channels' => $this->channelOptions($query['search'], $query['scope'], $userId),
            ],
        ]);
    }

    private function conversationListQuery(string $scope, string $search, string $channel, int $userId): Builder
    {
        $query = Conversation::query()
            ->with(['customer', 'assignedAdmin'])
            ->select('conversations.*')
            ->selectSub($this->latestMessagePreviewSubquery(), 'last_message_preview')
            ->selectSub($this->latestMessageSenderSubquery(), 'last_message_sender_type')
            ->selectSub($this->readService->unreadCountSubquery($userId), 'unread_messages_count')
            ->latest('last_message_at')
            ->latest('id');

        if ($channel !== 'all') {
            $query->where('conversations.channel', $channel);
        }

        if ($search !== '') {
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

    /**
     * @return array{scope: string, channel: string, search: string, page: int, per_page: int}
     */
    private function queryFromRequest(Request $request): array
    {
        $scope = $request->string('scope')->toString();
        $scope = array_key_exists($scope, self::FILTERS) ? $scope : 'all';

        $channel = $request->string('channel')->toString();
        $channel = array_key_exists($channel, self::CHANNELS) ? $channel : 'all';

        $search = trim((string) $request->input('search', ''));
        $page = max(1, $request->integer('page', 1));
        $perPage = min(50, max(1, $request->integer('per_page', 20)));

        return [
            'scope' => $scope,
            'channel' => $channel,
            'search' => $search,
            'page' => $page,
            'per_page' => $perPage,
        ];
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

    private function normalizePaginatorConversations(LengthAwarePaginator $paginator, int $userId): void
    {
        $normalized = $paginator->getCollection()->map(function (Conversation $conversation) use ($userId): Conversation {
            $attributes = [
                'last_message_preview' => $conversation->getAttribute('last_message_preview'),
                'last_message_sender_type' => $conversation->getAttribute('last_message_sender_type'),
                'unread_messages_count' => $conversation->getAttribute('unread_messages_count'),
            ];

            $conversation = $this->botToggleService->ensureAutoResumed($conversation, $userId);
            $conversation->loadMissing(['customer', 'assignedAdmin']);

            foreach ($attributes as $key => $value) {
                if ($value !== null && $conversation->getAttribute($key) === null) {
                    $conversation->setAttribute($key, $value);
                }
            }

            return $conversation;
        });

        $paginator->setCollection($normalized);
    }

    private function loadConversationDetail(Conversation $conversation): Conversation
    {
        $conversation = $this->botToggleService->ensureAutoResumed($conversation);

        $conversation->load([
            'customer.tags',
            'assignedAdmin',
            'handoffAdmin',
            'states' => fn ($query) => $query->active()->latest('updated_at')->limit(20),
            'bookingRequests' => fn ($query) => $query->latest()->limit(3),
            'escalations' => fn ($query) => $query->latest()->limit(5),
            'tags' => fn ($query) => $query->latest('created_at')->limit(20),
        ]);

        $messages = $conversation->messages()
            ->orderBy('sent_at')
            ->orderBy('id')
            ->limit(120)
            ->get();

        $conversation->setRelation('messages', $messages);

        return $conversation;
    }

    /**
     * @return array<string, mixed>
     */
    private function conversationListItem(Conversation $conversation, int $userId): array
    {
        $customer = $conversation->customer;
        $customerName = trim((string) ($customer?->name ?: 'Customer'));
        $lastMessageAt = $conversation->last_message_at;
        $unreadCount = (int) ($conversation->unread_messages_count ?? $this->readService->unreadCountForConversation($conversation, $userId));
        $autoResumeMinutes = $this->botToggleService->autoResumeMinutes();
        $resumeAt = $conversation->bot_auto_resume_at?->toIso8601String()
            ?? ($conversation->isAutomationSuppressed() && $conversation->last_admin_intervention_at !== null
                ? $conversation->last_admin_intervention_at->copy()->addMinutes($autoResumeMinutes)->toIso8601String()
                : null);
        $isAdminTakeover = $conversation->isAdminTakeover();
        $botControl = [
            'enabled' => ! $conversation->isAutomationSuppressed(),
            'paused' => (bool) $conversation->bot_paused,
            'human_takeover' => $isAdminTakeover,
            'auto_resume_enabled' => (bool) ($conversation->bot_auto_resume_enabled ?? false),
            'auto_resume_at' => $resumeAt,
            'last_admin_reply_at' => $conversation->bot_last_admin_reply_at?->toIso8601String(),
            'auto_resume_after_minutes' => $autoResumeMinutes,
        ];

        return [
            'id' => (int) $conversation->id,
            'channel' => (string) $conversation->channel,
            'channel_label' => $conversation->isMobileLiveChat() ? 'Live Chat' : 'WhatsApp',
            'customer_name' => $customerName,
            'customer_contact' => (string) ($customer?->display_contact ?? '-'),
            'title' => $customerName,
            'subtitle' => (string) ($conversation->last_message_preview ?? $conversation->summary ?? ''),
            'latest_message_preview' => (string) ($conversation->last_message_preview ?? ''),
            'last_message_preview' => (string) ($conversation->last_message_preview ?? ''),
            'last_message_at' => $lastMessageAt?->toIso8601String(),
            'status' => (string) $conversation->status?->value,
            'status_label' => $conversation->status?->label() ?? ucfirst((string) $conversation->status?->value),
            'operational_mode' => $conversation->currentOperationalMode(),
            'operational_mode_label' => $conversation->currentOperationalModeLabel(),
            'bot_enabled' => (bool) $botControl['enabled'],
            'bot_paused' => (bool) $conversation->bot_paused,
            'handoff_mode' => (string) ($conversation->handoff_mode ?? 'bot'),
            'bot_paused_reason' => $conversation->bot_paused_reason,
            'last_admin_intervention_at' => $conversation->last_admin_intervention_at?->toIso8601String(),
            'bot_auto_resume_after_minutes' => $autoResumeMinutes,
            'bot_auto_resume_enabled' => (bool) ($conversation->bot_auto_resume_enabled ?? false),
            'bot_auto_resume_at' => $resumeAt,
            'unread_count' => $unreadCount,
            'is_admin_takeover' => $isAdminTakeover,
            'bot_control' => $botControl,
            'badges' => array_values(array_filter([
                $conversation->currentOperationalModeLabel(),
                $conversation->is_urgent ? 'Urgent' : null,
                $conversation->needs_human ? 'Needs Human' : null,
            ])),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function conversationDetailPayload(Conversation $conversation, int $userId): array
    {
        $customer = $conversation->customer;
        $listItem = $this->conversationListItem($conversation, $userId);
        $botState = $this->botToggleService->statePayload($conversation);

        return array_merge($listItem, [
            'customer' => [
                'id' => (int) ($customer?->id ?? 0),
                'name' => (string) ($customer?->name ?? 'Customer'),
                'email' => (string) ($customer?->email ?? ''),
                'phone' => (string) ($customer?->display_contact ?? '-'),
                'display_contact' => (string) ($customer?->display_contact ?? '-'),
            ],
            'bot' => $botState,
            'bot_control' => array_merge(
                is_array($listItem['bot_control'] ?? null) ? $listItem['bot_control'] : [],
                [
                    'enabled' => (bool) ($botState['bot_enabled'] ?? false),
                    'paused' => (bool) ($botState['bot_paused'] ?? false),
                    'human_takeover' => (string) ($botState['handoff_mode'] ?? 'bot') === 'admin',
                    'auto_resume_enabled' => (bool) ($botState['bot_auto_resume_enabled'] ?? false),
                    'auto_resume_at' => $botState['bot_auto_resume_at'] ?? null,
                    'last_admin_reply_at' => $botState['bot_last_admin_reply_at'] ?? null,
                    'auto_resume_after_minutes' => (int) ($botState['bot_auto_resume_after_minutes'] ?? $this->botToggleService->autoResumeMinutes()),
                ],
            ),
            'quick_details' => [
                'channel' => $listItem['channel_label'],
                'status' => $listItem['status_label'],
                'mode' => $listItem['operational_mode_label'],
                'source_app' => (string) ($conversation->source_app ?? '-'),
                'current_intent' => (string) ($conversation->current_intent ?? '-'),
            ],
        ]);
    }

    /**
     * @param  array{slot_summary: Collection<int, array{label: string, value: string, palette: string|null}>, internal_notes: EloquentCollection, conversation_tags: EloquentCollection, customer_tags: EloquentCollection, audit_trail: EloquentCollection}  $insight
     * @return array<string, mixed>
     */
    private function insightPayload(Conversation $conversation, array $insight): array
    {
        return [
            'customer_name' => (string) ($conversation->customer?->name ?? 'Customer'),
            'customer_contact' => (string) ($conversation->customer?->display_contact ?? '-'),
            'customer_tags' => $insight['customer_tags']->map(fn ($tag): array => [
                'label' => (string) ($tag->tag ?? $tag->name ?? ''),
            ])->values()->all(),
            'conversation_tags' => $insight['conversation_tags']->map(fn ($tag): array => [
                'label' => (string) ($tag->tag ?? $tag->name ?? ''),
            ])->values()->all(),
            'quick_details' => $insight['slot_summary']->mapWithKeys(fn (array $item): array => [
                $item['label'] => $item['value'],
            ])->all(),
            'note_lines' => $insight['internal_notes']->map(fn ($note): array => [
                'text' => trim((string) ($note->note ?? $note->content ?? '')),
            ])->filter(fn (array $note): bool => $note['text'] !== '')->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function threadMessagePayload(ConversationMessage $message): array
    {
        $senderLabel = match ((string) $message->sender_type?->value) {
            SenderType::Admin->value, SenderType::Agent->value => 'Admin',
            SenderType::Bot->value => 'Bot',
            default => 'Customer',
        };

        return [
            'id' => (int) $message->id,
            'message_id' => (int) $message->id,
            'sender_type' => (string) ($message->sender_type?->value ?? ''),
            'direction' => (string) ($message->direction?->value ?? ''),
            'sender_label' => $senderLabel,
            'message_text' => (string) ($message->message_text ?? ''),
            'text' => (string) ($message->message_text ?? ''),
            'sent_at' => $message->sent_at?->toIso8601String() ?? $message->created_at?->toIso8601String(),
            'created_at' => $message->created_at?->toIso8601String(),
            'is_mine' => in_array((string) ($message->direction?->value ?? ''), [MessageDirection::Outbound->value], true),
            'delivery_status' => (string) ($message->delivery_status?->value ?? ''),
            'status_label' => ucfirst((string) ($message->delivery_status?->value ?? 'sent')),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function filterOptions(string $search, string $channel, int $userId): array
    {
        return collect(self::FILTERS)->map(function (string $label, string $key) use ($search, $channel, $userId): array {
            $count = $this->conversationListQuery($key, $search, $channel, $userId)->count();

            return [
                'key' => $key,
                'label' => $label,
                'count' => $count,
            ];
        })->values()->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function channelOptions(string $search, string $scope, int $userId): array
    {
        return collect(self::CHANNELS)->map(function (string $label, string $key) use ($search, $scope, $userId): array {
            $count = $this->conversationListQuery($scope, $search, $key, $userId)->count();

            return [
                'key' => $key,
                'label' => $label,
                'count' => $count,
            ];
        })->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function dashboardSummaryPayload(): array
    {
        $dashboard = $this->insightService->dashboard();

        return [
            'unread_total' => Conversation::query()->where('last_read_at_admin', '<', DB::raw('COALESCE(last_message_at, created_at)'))->count(),
            'active_conversations' => Conversation::query()->whereNotIn('status', ['closed', 'archived'])->count(),
            'conversation_by_status' => $dashboard['conversation_by_status'] ?? [],
            'escalations_today' => $dashboard['escalations_today'] ?? [],
            'booking_conversion' => $dashboard['booking_conversion'] ?? [],
            'failed_message_insight' => $dashboard['failed_message_insight'] ?? [],
        ];
    }
}
