<?php

namespace App\Services\Chatbot;

use App\Enums\MessageDirection;
use App\Enums\SenderType;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ConversationUserRead;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

class ConversationReadService
{
    public function markAsRead(Conversation $conversation, int $userId): ConversationUserRead
    {
        $latestInbound = $conversation->messages()
            ->where('direction', MessageDirection::Inbound->value)
            ->where('sender_type', SenderType::Customer->value)
            ->latest('id')
            ->first(['id', 'sent_at']);

        return ConversationUserRead::query()->updateOrCreate(
            [
                'conversation_id' => $conversation->id,
                'user_id' => $userId,
            ],
            [
                'last_read_message_id' => $latestInbound?->id,
                'last_read_at' => $latestInbound?->sent_at ?? now(),
                'last_seen_at' => now(),
            ],
        );
    }

    public function touchSeen(Conversation $conversation, int $userId): ConversationUserRead
    {
        return ConversationUserRead::query()->updateOrCreate(
            [
                'conversation_id' => $conversation->id,
                'user_id' => $userId,
            ],
            [
                'last_seen_at' => now(),
            ],
        );
    }

    public function unreadCountSubquery(int $userId): QueryBuilder
    {
        return DB::table('conversation_messages as unread_messages')
            ->selectRaw('COUNT(*)')
            ->whereColumn('unread_messages.conversation_id', 'conversations.id')
            ->where('unread_messages.direction', MessageDirection::Inbound->value)
            ->where('unread_messages.sender_type', SenderType::Customer->value)
            ->whereRaw(
                'unread_messages.id > COALESCE((SELECT cur.last_read_message_id FROM conversation_user_reads as cur WHERE cur.conversation_id = conversations.id AND cur.user_id = ? LIMIT 1), 0)',
                [$userId],
            );
    }

    public function applyUnreadFilter(Builder $query, int $userId): void
    {
        $query->whereExists(function ($sub) use ($userId): void {
            $sub->selectRaw('1')
                ->from('conversation_messages as unread_messages')
                ->whereColumn('unread_messages.conversation_id', 'conversations.id')
                ->where('unread_messages.direction', MessageDirection::Inbound->value)
                ->where('unread_messages.sender_type', SenderType::Customer->value)
                ->whereRaw(
                    'unread_messages.id > COALESCE((SELECT cur.last_read_message_id FROM conversation_user_reads as cur WHERE cur.conversation_id = conversations.id AND cur.user_id = ? LIMIT 1), 0)',
                    [$userId],
                );
        });
    }

    public function unreadCountForConversation(Conversation $conversation, int $userId): int
    {
        $lastReadMessageId = ConversationUserRead::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $userId)
            ->value('last_read_message_id');

        return ConversationMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('direction', MessageDirection::Inbound->value)
            ->where('sender_type', SenderType::Customer->value)
            ->when($lastReadMessageId !== null, fn (Builder $builder) => $builder->where('id', '>', $lastReadMessageId))
            ->count();
    }
}
