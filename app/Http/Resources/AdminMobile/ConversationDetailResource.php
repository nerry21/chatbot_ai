<?php

namespace App\Http\Resources\AdminMobile;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Conversation */
class ConversationDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $base = (new ConversationListItemResource($this->resource))->toArray($request);
        $latestMessage = $this->relationLoaded('messages')
            ? $this->messages->first()
            : null;

        return array_merge($base, [
            'channel_conversation_id' => $this->channel_conversation_id,
            'summary' => $this->summary,
            'current_intent' => $this->current_intent,
            'is_whatsapp' => $this->isWhatsApp(),
            'is_mobile_live_chat' => $this->isMobileLiveChat(),
            'bot_paused' => (bool) $this->bot_paused,
            'bot_paused_reason' => $this->bot_paused_reason,
            'started_at' => $this->started_at?->toIso8601String(),
            'last_read_at_customer' => $this->last_read_at_customer?->toIso8601String(),
            'last_read_at_admin' => $this->last_read_at_admin?->toIso8601String(),
            'last_admin_intervention_at' => $this->last_admin_intervention_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'reopened_at' => $this->reopened_at?->toIso8601String(),
            'handoff_admin' => $this->relationLoaded('handoffAdmin') && $this->handoffAdmin !== null
                ? [
                    'id' => $this->handoffAdmin->id,
                    'name' => $this->handoffAdmin->name,
                ]
                : null,
            'latest_message_id' => $latestMessage?->id,
            'latest_message' => $latestMessage !== null
                ? new ConversationMessageResource($latestMessage)
                : null,
        ]);
    }
}
