<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Services\Chatbot\AdminConversationWorkspaceService;
use App\Services\Chatbot\ConversationReadService;
use App\Services\Mobile\MobileConversationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LiveChatController extends Controller
{
    public function __construct(
        private readonly AdminConversationWorkspaceService $workspaceService,
        private readonly ConversationReadService $readService,
        private readonly MobileConversationService $mobileConversationService,
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
            $this->mobileConversationService->touchAdminRead($conversation);
        }

        return response()->json([
            'ok' => true,
            'conversation_id' => $conversation->id,
            'channel' => $conversation->channel,
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
        return $this->workspaceService->workspaceData(
            userId: (int) (auth()->id() ?? 0),
            filters: $request->all(),
            selectedConversation: $selectedConversation,
            selectedConversationId: $selectedConversationId,
            markRead: $markRead,
        );
    }
}
