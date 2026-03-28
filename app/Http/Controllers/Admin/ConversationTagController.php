<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Services\Chatbot\ConversationTagService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ConversationTagController extends Controller
{
    public function store(
        Request $request,
        Conversation $conversation,
        ConversationTagService $tagService,
    ): RedirectResponse {
        $validated = $request->validate([
            'tag' => ['required', 'string', 'min:2', 'max:40', 'regex:/[A-Za-z0-9]/'],
            'target' => ['required', 'in:conversation,customer'],
        ]);

        if ($validated['target'] === 'customer') {
            if ($conversation->customer === null) {
                return back()->with('error', 'Customer pada conversation ini tidak ditemukan.');
            }

            $tagService->addCustomerTag(
                customer: $conversation->customer,
                tag: $validated['tag'],
                actorId: (int) auth()->id(),
                conversation: $conversation,
            );

            return back()->with('success', 'Tag customer berhasil ditambahkan.');
        }

        $tagService->addConversationTag(
            conversation: $conversation,
            tag: $validated['tag'],
            actorId: (int) auth()->id(),
        );

        return back()->with('success', 'Tag conversation berhasil ditambahkan.');
    }
}
