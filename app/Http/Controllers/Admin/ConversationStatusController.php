<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Services\Chatbot\ConversationStatusService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ConversationStatusController extends Controller
{
    public function escalate(
        Request $request,
        Conversation $conversation,
        ConversationStatusService $statusService,
    ): RedirectResponse {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $statusService->markEscalated(
            conversation: $conversation,
            actorId: (int) auth()->id(),
            reason: $validated['reason'] ?? null,
        );

        return back()->with('success', 'Conversation ditandai escalated dan bot dipause dengan aman.');
    }

    public function urgent(
        Request $request,
        Conversation $conversation,
        ConversationStatusService $statusService,
    ): RedirectResponse {
        $validated = $request->validate([
            'urgent' => ['required', 'boolean'],
        ]);

        $statusService->setUrgency(
            conversation: $conversation,
            actorId: (int) auth()->id(),
            urgent: (bool) $validated['urgent'],
        );

        return back()->with('success', (bool) $validated['urgent']
            ? 'Conversation ditandai urgent.'
            : 'Tanda urgent berhasil dibersihkan.');
    }

    public function close(
        Request $request,
        Conversation $conversation,
        ConversationStatusService $statusService,
    ): RedirectResponse {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $statusService->close(
            conversation: $conversation,
            actorId: (int) auth()->id(),
            reason: $validated['reason'] ?? null,
        );

        return back()->with('success', 'Conversation berhasil ditutup.');
    }

    public function reopen(
        Request $request,
        Conversation $conversation,
        ConversationStatusService $statusService,
    ): RedirectResponse {
        $statusService->reopen(
            conversation: $conversation,
            actorId: (int) auth()->id(),
        );

        return back()->with('success', 'Conversation berhasil dibuka kembali.');
    }
}
