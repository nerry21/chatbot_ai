<?php

namespace App\Http\Resources\AdminMobile;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * @mixin array{
 *     items: Collection<int, \App\Models\Conversation>,
 *     pagination: array<string, mixed>,
 *     selected_conversation_id: int|null,
 *     refreshed_at: string|null,
 *     sort: array{by: string, direction: string}
 * }
 */
class ConversationListPayloadResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'items' => ConversationListItemResource::collection($this['items']),
            'pagination' => $this['pagination'],
            'selected_conversation_id' => $this['selected_conversation_id'],
            'refreshed_at' => $this['refreshed_at'],
            'sort' => [
                'by' => data_get($this['sort'], 'by', 'last_message_at'),
                'direction' => data_get($this['sort'], 'direction', 'desc'),
            ],
        ];
    }
}
