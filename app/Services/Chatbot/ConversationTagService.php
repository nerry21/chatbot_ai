<?php

namespace App\Services\Chatbot;

use App\Enums\AuditActionType;
use App\Models\Conversation;
use App\Models\ConversationTag;
use App\Models\Customer;
use App\Models\CustomerTag;
use App\Services\Support\AuditLogService;
use Illuminate\Support\Str;

class ConversationTagService
{
    public function __construct(
        private readonly AuditLogService $audit,
    ) {}

    public function addConversationTag(Conversation $conversation, string $tag, int $actorId): ConversationTag
    {
        $normalizedTag = $this->normalizeTag($tag);

        $conversationTag = ConversationTag::query()->firstOrCreate(
            [
                'conversation_id' => $conversation->id,
                'tag' => $normalizedTag,
            ],
            [
                'created_by' => $actorId,
            ],
        );

        $this->audit->record(AuditActionType::ConversationTagged, [
            'actor_user_id' => $actorId,
            'conversation_id' => $conversation->id,
            'auditable_type' => ConversationTag::class,
            'auditable_id' => $conversationTag->id,
            'message' => "Tag {$normalizedTag} ditambahkan ke percakapan.",
            'context' => [
                'tag' => $normalizedTag,
                'customer_id' => $conversation->customer_id,
            ],
        ]);

        return $conversationTag->fresh(['creator']);
    }

    public function addCustomerTag(Customer $customer, string $tag, int $actorId, ?Conversation $conversation = null): CustomerTag
    {
        $normalizedTag = $this->normalizeTag($tag);
        $customerTag = CustomerTag::query()->firstOrCreate([
            'customer_id' => $customer->id,
            'tag' => $normalizedTag,
        ]);

        $this->audit->record(AuditActionType::CustomerTagged, [
            'actor_user_id' => $actorId,
            'conversation_id' => $conversation?->id,
            'auditable_type' => CustomerTag::class,
            'auditable_id' => $customerTag->id,
            'message' => "Tag {$normalizedTag} ditambahkan ke customer.",
            'context' => [
                'tag' => $normalizedTag,
                'customer_id' => $customer->id,
            ],
        ]);

        return $customerTag;
    }

    private function normalizeTag(string $tag): string
    {
        return Str::of($tag)
            ->trim()
            ->lower()
            ->replaceMatches('/\s+/', '-')
            ->replaceMatches('/[^a-z0-9\-_]/', '')
            ->substr(0, 40)
            ->value();
    }
}
