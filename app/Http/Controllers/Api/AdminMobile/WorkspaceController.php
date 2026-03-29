<?php

namespace App\Http\Controllers\Api\AdminMobile;

use App\Http\Controllers\Api\AdminMobile\Concerns\RespondsWithAdminMobileJson;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\AdminMobile\ConversationIndexRequest;
use App\Http\Resources\AdminMobile\ConversationDetailResource;
use App\Http\Resources\AdminMobile\ConversationListPayloadResource;
use App\Http\Resources\AdminMobile\ConversationMessageResource;
use App\Http\Resources\AdminMobile\InsightPaneResource;
use App\Http\Resources\AdminMobile\ThreadGroupResource;
use App\Services\AdminMobile\AdminMobileAuthService;
use App\Services\Chatbot\AdminConversationWorkspaceService;
use Illuminate\Http\JsonResponse;

class WorkspaceController extends Controller
{
    use RespondsWithAdminMobileJson;

    public function __construct(
        private readonly AdminMobileAuthService $adminMobileAuthService,
        private readonly AdminConversationWorkspaceService $workspaceService,
    ) {}

    public function workspace(ConversationIndexRequest $request): JsonResponse
    {
        $user = $this->adminMobileAuthService->currentUser($request);
        $payload = $this->workspaceService->workspaceData(
            userId: $user->id,
            filters: $request->validated(),
            selectedConversationId: $request->validated('selected_conversation_id'),
            markRead: false,
        );

        return $this->successResponse('Workspace admin mobile berhasil diambil.', [
            'summary_counts' => $payload['summaryCounts'],
            'filters_meta' => $payload['filtersMeta'],
            'conversation_list' => (new ConversationListPayloadResource([
                'items' => $payload['conversations']->getCollection(),
                'pagination' => $payload['pagination'],
                'selected_conversation_id' => $payload['selectedConversationId'],
                'refreshed_at' => $payload['lastUpdatedAt'],
                'sort' => [
                    'by' => $payload['sortBy'] ?? 'last_message_at',
                    'direction' => $payload['sortDir'] ?? 'desc',
                ],
            ]))->resolve(),
            'selected_conversation' => $payload['selectedConversation'] !== null
                ? new ConversationDetailResource($payload['selectedConversation'])
                : null,
            'messages' => ConversationMessageResource::collection($payload['messages']),
            'thread_groups' => ThreadGroupResource::collection($payload['threadGroups']),
            'insight_pane' => $payload['insightPane'] !== null
                ? new InsightPaneResource($payload['insightPane'])
                : null,
        ]);
    }

    public function filters(ConversationIndexRequest $request): JsonResponse
    {
        $user = $this->adminMobileAuthService->currentUser($request);
        $validated = $request->validated();
        $channel = (string) ($validated['channel'] ?? 'all');
        $search = trim((string) ($validated['search'] ?? ''));
        $sortBy = (string) ($validated['sort_by'] ?? 'last_message_at');
        $sortDir = (string) ($validated['sort_dir'] ?? 'desc');

        return $this->successResponse('Meta filter admin mobile berhasil diambil.', [
            'filters_meta' => $this->workspaceService->filtersMeta(
                scope: (string) ($validated['scope'] ?? 'all'),
                channel: $channel,
                search: $search,
                perPage: isset($validated['per_page']) ? (int) $validated['per_page'] : null,
                sortBy: $sortBy,
                sortDir: $sortDir,
            ),
            'summary_counts' => $this->workspaceService->summaryCounts(
                userId: $user->id,
                search: $search,
                channel: $channel,
            ),
        ]);
    }
}
