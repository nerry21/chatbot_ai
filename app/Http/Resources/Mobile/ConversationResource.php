<?php

namespace App\Http\Resources\Mobile;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Conversation */
class ConversationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $latestMessage = $this->relationLoaded('messages')
            ? $this->messages->sortByDesc('id')->first()
            : $this->messages()
                ->where('sender_type', '!=', \App\Enums\SenderType::System->value)
                ->latest('id')
                ->first();

        return [
            'id' => $this->id,
            'channel' => $this->channel,
            'channel_label' => $this->channel_label,
            'is_whatsapp' => $this->isWhatsApp(),
            'is_mobile_live_chat' => $this->isMobileLiveChat(),
            'channel_conversation_id' => $this->channel_conversation_id,
            'source_app' => $this->source_app,
            'source_label' => $this->source_label,
            'is_from_mobile_app' => (bool) $this->is_from_mobile_app,
            'status' => is_string($this->status) ? $this->status : $this->status?->value,
            'operational_mode' => $this->currentOperationalMode(),
            'operational_mode_label' => $this->currentOperationalModeLabel(),
            'needs_human' => (bool) $this->needs_human,
            'handoff_mode' => $this->handoff_mode,
            'started_at' => $this->started_at?->toIso8601String(),
            'last_message_at' => $this->last_message_at?->toIso8601String(),
            'last_read_at_customer' => $this->last_read_at_customer?->toIso8601String(),
            'last_read_at_admin' => $this->last_read_at_admin?->toIso8601String(),
            'unread_count' => (int) ($this->unread_messages_count ?? $this->mobile_unread_count ?? 0),
            'latest_message_id' => $latestMessage?->id,
            'latest_message' => $latestMessage ? new ConversationMessageResource($latestMessage) : null,
            'latest_message_preview' => $latestMessage?->textPreview(120),
            'customer' => $this->relationLoaded('customer')
                ? new CustomerResource($this->customer)
                : null,
        ];
    }
}
