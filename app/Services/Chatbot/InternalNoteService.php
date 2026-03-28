<?php

namespace App\Services\Chatbot;

use App\Enums\AuditActionType;
use App\Models\AdminNote;
use App\Models\Conversation;
use App\Models\Customer;
use App\Services\Support\AuditLogService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

class InternalNoteService
{
    public function __construct(
        private readonly AuditLogService $audit,
    ) {}

    public function addConversationNote(
        Conversation $conversation,
        string $body,
        int $authorId,
    ): AdminNote {
        $note = $conversation->adminNotes()->create([
            'conversation_id' => $conversation->id,
            'customer_id' => $conversation->customer_id,
            'author_id' => $authorId,
            'body' => trim($body),
        ]);

        $this->audit->record(AuditActionType::InternalNoteCreated, [
            'actor_user_id' => $authorId,
            'conversation_id' => $conversation->id,
            'auditable_type' => AdminNote::class,
            'auditable_id' => $note->id,
            'message' => 'Catatan internal percakapan ditambahkan.',
            'context' => [
                'target' => 'conversation',
                'customer_id' => $conversation->customer_id,
                'note_preview' => mb_substr($note->body, 0, 160),
            ],
        ]);

        return $note->fresh(['author']);
    }

    public function addCustomerNote(
        Customer $customer,
        string $body,
        int $authorId,
        ?Conversation $conversation = null,
    ): AdminNote {
        $note = $customer->adminNotes()->create([
            'conversation_id' => $conversation?->id,
            'customer_id' => $customer->id,
            'author_id' => $authorId,
            'body' => trim($body),
        ]);

        $this->audit->record(AuditActionType::InternalNoteCreated, [
            'actor_user_id' => $authorId,
            'conversation_id' => $conversation?->id,
            'auditable_type' => AdminNote::class,
            'auditable_id' => $note->id,
            'message' => 'Catatan internal customer ditambahkan.',
            'context' => [
                'target' => 'customer',
                'customer_id' => $customer->id,
                'note_preview' => mb_substr($note->body, 0, 160),
            ],
        ]);

        return $note->fresh(['author']);
    }

    /**
     * @return EloquentCollection<int, AdminNote>
     */
    public function recentForConversation(Conversation $conversation, int $limit = 8): EloquentCollection
    {
        return AdminNote::query()
            ->with('author')
            ->where(function ($query) use ($conversation): void {
                $query->where('conversation_id', $conversation->id);

                if ($conversation->customer_id !== null) {
                    $query->orWhere(function ($customerQuery) use ($conversation): void {
                        $customerQuery
                            ->where('customer_id', $conversation->customer_id)
                            ->where('noteable_type', Customer::class);
                    });
                }
            })
            ->latest('created_at')
            ->limit($limit)
            ->get();
    }
}
