<?php

namespace App\Http\Resources\Mobile;

use App\Enums\MessageDirection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\ConversationMessage */
class ConversationMessageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $direction = is_string($this->direction) ? $this->direction : $this->direction?->value;
        $senderType = is_string($this->sender_type) ? $this->sender_type : $this->sender_type?->value;
        $deliveryStatus = is_string($this->delivery_status) ? $this->delivery_status : $this->delivery_status?->value;

        if (
            $direction === MessageDirection::Inbound->value
            && in_array($deliveryStatus, [null, 'pending'], true)
        ) {
            $deliveryStatus = 'sent';
        }

        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'direction' => $direction,
            'sender_type' => $senderType,
            'sender_user_id' => $this->sender_user_id,
            'message_type' => $this->message_type,
            'message_text' => $this->message_text,
            'client_message_id' => $this->client_message_id,
            'channel_message_id' => $this->channel_message_id,
            'delivery_status' => $deliveryStatus,
            'delivery_error' => $this->delivery_error,
            'is_delivered_to_app' => $this->delivered_to_app_at !== null,
            'is_read_by_customer' => $this->read_at !== null,
            'read_at' => $this->read_at?->toIso8601String(),
            'delivered_to_app_at' => $this->delivered_to_app_at?->toIso8601String(),
            'sent_at' => $this->sent_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'is_fallback' => (bool) $this->is_fallback,
            'is_mine' => $direction === MessageDirection::Inbound->value && $senderType === 'customer',
        ];
    }
}
