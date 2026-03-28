<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Services\Chatbot\InternalNoteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ConversationInternalNoteController extends Controller
{
    public function store(
        Request $request,
        Conversation $conversation,
        InternalNoteService $noteService,
    ): RedirectResponse {
        $validated = $request->validate([
            'body' => ['required', 'string', 'min:3', 'max:3000'],
            'target' => ['required', 'in:conversation,customer'],
        ]);

        if ($validated['target'] === 'customer') {
            if ($conversation->customer === null) {
                return back()->with('error', 'Customer pada conversation ini tidak ditemukan.');
            }

            $noteService->addCustomerNote(
                customer: $conversation->customer,
                body: $validated['body'],
                authorId: (int) auth()->id(),
                conversation: $conversation,
            );

            return back()->with('success', 'Catatan internal customer berhasil ditambahkan.');
        }

        $noteService->addConversationNote(
            conversation: $conversation,
            body: $validated['body'],
            authorId: (int) auth()->id(),
        );

        return back()->with('success', 'Catatan internal conversation berhasil ditambahkan.');
    }
}
