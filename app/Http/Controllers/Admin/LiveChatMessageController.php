<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SendConversationReplyRequest;
use App\Models\Conversation;
use App\Services\Chatbot\AdminConversationMessageService;
use Illuminate\Http\RedirectResponse;

class LiveChatMessageController extends Controller
{
    public function store(
        SendConversationReplyRequest $request,
        Conversation $conversation,
        AdminConversationMessageService $messageService,
    ): RedirectResponse {
        $result = $messageService->send(
            conversation: $conversation,
            text: (string) $request->input('message'),
            adminId: (int) auth()->id(),
            source: 'live_chat_panel',
        );

        $parameters = array_merge(['conversation' => $conversation], $request->query());

        if ($result['status'] === 'failed') {
            return redirect()
                ->route('admin.chatbot.live-chats.show', $parameters)
                ->with('error', $result['error'] ?? 'Pesan gagal dikirim.');
        }

        if ($result['duplicate']) {
            return redirect()
                ->route('admin.chatbot.live-chats.show', $parameters)
                ->with('success', 'Pesan yang sama sudah diantrekan. Duplikat diabaikan.');
        }

        return redirect()
            ->route('admin.chatbot.live-chats.show', $parameters)
            ->with('success', $this->successMessage($result['transport'] ?? $conversation->channel));
    }

    private function successMessage(string $transport): string
    {
        return $transport === 'mobile_live_chat'
            ? 'Pesan admin berhasil dikirim ke live chat pelanggan.'
            : 'Pesan admin berhasil diantrekan ke customer.';
    }
}
