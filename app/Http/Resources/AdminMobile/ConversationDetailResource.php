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
        $resumeAt = $this->bot_auto_resume_at?->toIso8601String()
            ?? ($this->isAutomationSuppressed() && $this->last_admin_intervention_at !== null
                ? $this->last_admin_intervention_at->copy()->addMinutes((int) config('chatbot.admin_mobile.bot_auto_resume_after_minutes', 15))->toIso8601String()
                : null);

        return array_merge($base, [
            'channel_conversation_id' => $this->channel_conversation_id,
            'summary' => $this->summary,
            'current_intent' => $this->current_intent,
            'is_whatsapp' => $this->isWhatsApp(),
            'is_mobile_live_chat' => $this->isMobileLiveChat(),
            'bot' => [
                'enabled' => ! $this->isAutomationSuppressed(),
                'paused' => (bool) $this->bot_paused,
                'paused_reason' => $this->bot_paused_reason,
                'auto_resume_after_minutes' => (int) config('chatbot.admin_mobile.bot_auto_resume_after_minutes', 15),
                'auto_resume_at' => $resumeAt,
            ],
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
            'merged_conversation_count' => (int) ($this->merged_conversation_count ?? 1),
            'merged_conversation_ids' => collect($this->merged_conversation_ids ?? [])->values()->all(),
            'bot_control' => [
                'enabled' => ! $this->isAdminTakeover() && ! $this->isBotPaused(),
                'paused' => (bool) $this->bot_paused,
                'human_takeover' => $this->isAdminTakeover(),
                'auto_resume_enabled' => (bool) ($this->bot_auto_resume_enabled ?? false),
                'auto_resume_at' => $resumeAt,
                'last_admin_reply_at' => $this->bot_last_admin_reply_at?->toIso8601String(),
            ],
        ]);
    }
}
