<?php

namespace App\Http\Resources\AdminMobile;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin array{date_label: string, messages: \Illuminate\Support\Collection<int, \App\Models\ConversationMessage>}
 */
class ThreadGroupResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'date_label' => $this['date_label'],
            'messages' => ConversationMessageResource::collection($this['messages']),
        ];
    }
}
