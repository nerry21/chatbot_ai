<?php

namespace App\Http\Controllers\Api\AdminMobile;

use App\Http\Controllers\Api\AdminMobile\Concerns\RespondsWithAdminMobileJson;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\AdminMobile\ConversationIndexRequest;
use App\Http\Requests\Api\AdminMobile\ConversationPollRequest;
use App\Http\Resources\AdminMobile\ConversationDetailResource;
use App\Http\Resources\AdminMobile\ConversationListPayloadResource;
use App\Http\Resources\AdminMobile\ConversationMessageResource;
use App\Http\Resources\AdminMobile\InsightPaneResource;
use App\Http\Resources\AdminMobile\ThreadGroupResource;
use App\Models\Conversation;
use App\Services\AdminMobile\AdminMobileAuthService;
use App\Services\Chatbot\AdminConversationWorkspaceService;
use Illuminate\Http\JsonResponse;

class ConversationController extends Controller
{
    use RespondsWithAdminMobileJson;

    public function __construct(
        private readonly AdminMobileAuthService $adminMobileAuthService,
        private readonly AdminConversationWorkspaceService $workspaceService,
    ) {}

    public function index(ConversationIndexRequest $request): JsonResponse
    {
        $user = $this->adminMobileAuthService->currentUser($request);
        $payload = $this->workspaceService->listData($user->id, $request->validated());

        return $this->successResponse('Daftar conversation admin mobile berhasil diambil.', [
            'summary_counts' => $payload['summaryCounts'],
            'filters_meta' => $payload['filtersMeta'],
            'conversation_list' => $this->conversationListPayload($payload),
        ]);
    }

    public function show(ConversationIndexRequest $request, Conversation $conversation): JsonResponse
    {
        $user = $this->adminMobileAuthService->currentUser($request);
        $payload = $this->workspaceService->conversationDetailData($conversation, $user->id, false);

        return $this->successResponse('Detail conversation admin mobile berhasil diambil.', [
            'selected_conversation' => new ConversationDetailResource($payload['selectedConversation']),
            'messages' => ConversationMessageResource::collection($payload['messages']),
            'thread_groups' => ThreadGroupResource::collection($payload['threadGroups']),
            'insight_pane' => new InsightPaneResource($payload['insightPane']),
            'meta' => [
                'message_order' => 'desc',
                'latest_message_id' => $payload['messages']->first()?->id,
                'refreshed_at' => $payload['lastUpdatedAt'],
            ],
        ]);
    }

    public function messages(ConversationIndexRequest $request, Conversation $conversation): JsonResponse
    {
        $user = $this->adminMobileAuthService->currentUser($request);
        $payload = $this->workspaceService->conversationDetailData($conversation, $user->id, false);

        return $this->successResponse('Daftar pesan conversation admin mobile berhasil diambil.', [
            'selected_conversation' => new ConversationDetailResource($payload['selectedConversation']),
            'messages' => ConversationMessageResource::collection($payload['messages']),
            'thread_groups' => ThreadGroupResource::collection($payload['threadGroups']),
            'meta' => [
                'message_order' => 'desc',
                'count' => $payload['messages']->count(),
                'latest_message_id' => $payload['messages']->first()?->id,
                'refreshed_at' => $payload['lastUpdatedAt'],
            ],
        ]);
    }

    public function poll(ConversationPollRequest $request, Conversation $conversation): JsonResponse
    {
        $user = $this->adminMobileAuthService->currentUser($request);
        $payload = $this->workspaceService->pollConversationData(
            conversation: $conversation,
            userId: $user->id,
            afterMessageId: $request->validated('after_message_id'),
        );

        return $this->successResponse('Polling conversation admin mobile berhasil.', [
            'selected_conversation' => new ConversationDetailResource($payload['selectedConversation']),
            'messages' => ConversationMessageResource::collection($payload['messages']),
            'thread_groups' => ThreadGroupResource::collection($payload['threadGroups']),
            'meta' => [
                'message_order' => 'asc',
                'latest_message_id' => $payload['latestMessageId'],
                'delta_count' => $payload['deltaCount'],
                'unread_count' => $payload['unreadCount'],
                'poll_interval_ms' => $payload['pollIntervalMs'],
                'refreshed_at' => $payload['lastUpdatedAt'],
            ],
        ]);
    }

    public function pollList(ConversationIndexRequest $request): JsonResponse
    {
        $user = $this->adminMobileAuthService->currentUser($request);
        $payload = $this->workspaceService->listData($user->id, $request->validated());

        return $this->successResponse('Polling daftar conversation admin mobile berhasil.', [
            'summary_counts' => $payload['summaryCounts'],
            'filters_meta' => $payload['filtersMeta'],
            'conversation_list' => $this->conversationListPayload($payload),
            'meta' => [
                'refreshed_at' => $payload['lastUpdatedAt'],
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function conversationListPayload(array $payload): array
    {
        return (new ConversationListPayloadResource([
            'items' => $payload['conversations']->getCollection(),
            'pagination' => $payload['pagination'],
            'selected_conversation_id' => $payload['selectedConversationId'],
            'refreshed_at' => $payload['lastUpdatedAt'],
            'sort' => [
                'by' => $payload['sortBy'] ?? 'last_message_at',
                'direction' => $payload['sortDir'] ?? 'desc',
            ],
        ]))->resolve();
    }
}
