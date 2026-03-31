<?php

namespace App\Http\Resources\AdminMobile;

use App\Enums\ConversationStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/** @mixin \App\Models\Conversation */
class ConversationListItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $status = is_string($this->status) ? $this->status : $this->status?->value;
        $lastMessageAt = $this->last_message_at;
        $lastMessageSenderType = (string) ($this->last_message_sender_type ?? '');
        $lastMessagePreview = trim((string) ($this->last_message_preview ?? ''));
        $mergedConversationIds = collect($this->merged_conversation_ids ?? [])->values()->all();
        $resumeAt = $this->bot_auto_resume_at?->toIso8601String()
            ?? ($this->isAutomationSuppressed() && $this->last_admin_intervention_at !== null
                ? $this->last_admin_intervention_at->copy()->addMinutes((int) config('chatbot.admin_mobile.bot_auto_resume_after_minutes', 15))->toIso8601String()
                : null);

        return [
            'id' => $this->id,
            'merge_key' => $this->merge_key,
            'merged_conversation_count' => (int) ($this->merged_conversation_count ?? max(1, count($mergedConversationIds))),
            'merged_conversation_ids' => $mergedConversationIds,
            'customer' => $this->relationLoaded('customer') && $this->customer !== null
                ? new CustomerProfileResource($this->customer)
                : null,
            'customer_id' => (int) ($this->customer_id ?? 0),
            'customer_phone_e164' => $this->relationLoaded('customer') && $this->customer !== null
                ? $this->customer->phone_e164
                : null,
            'channel' => $this->channel,
            'channel_label' => $this->channel_label,
            'source_app' => $this->source_app,
            'source_label' => $this->source_label,
            'status' => $status,
            'status_label' => ConversationStatus::tryFrom((string) $status)?->label()
                ?? Str::headline((string) $status),
            'operational_mode' => $this->currentOperationalMode(),
            'operational_mode_label' => $this->currentOperationalModeLabel(),
            'bot_enabled' => ! $this->isAutomationSuppressed(),
            'bot_paused' => (bool) $this->bot_paused,
            'bot_paused_reason' => $this->bot_paused_reason,
            'bot_auto_resume_after_minutes' => (int) config('chatbot.admin_mobile.bot_auto_resume_after_minutes', 15),
            'bot_auto_resume_at' => $resumeAt,
            'operational_mode_palette' => $this->currentOperationalModePalette(),
            'needs_human' => (bool) $this->needs_human,
            'is_urgent' => (bool) $this->is_urgent,
            'is_admin_takeover' => $this->isAdminTakeover(),
            'handoff_mode' => $this->handoff_mode,
            'assigned_admin' => $this->relationLoaded('assignedAdmin') && $this->assignedAdmin !== null
                ? [
                    'id' => $this->assignedAdmin->id,
                    'name' => $this->assignedAdmin->name,
                ]
                : null,
            'unread_count' => (int) ($this->unread_messages_count ?? 0),
            'last_message_preview' => $this->decoratedPreview($lastMessageSenderType, $lastMessagePreview),
            'last_message_sender_type' => $lastMessageSenderType !== '' ? $lastMessageSenderType : null,
            'last_message_at' => $lastMessageAt?->toIso8601String(),
            'last_activity' => [
                'at' => $lastMessageAt?->toIso8601String(),
                'label' => $lastMessageAt?->diffForHumans(),
            ],
            'badges' => $this->badges($status),
            'bot_control' => [
                'enabled' => ! $this->isAdminTakeover() && ! $this->isBotPaused(),
                'paused' => (bool) $this->bot_paused,
                'human_takeover' => $this->isAdminTakeover(),
                'auto_resume_enabled' => (bool) ($this->bot_auto_resume_enabled ?? false),
                'auto_resume_at' => $resumeAt,
                'last_admin_reply_at' => $this->bot_last_admin_reply_at?->toIso8601String(),
            ],
        ];
    }

    /**
     * @return array<int, array{key: string, label: string, palette: string}>
     */
    private function badges(?string $status): array
    {
        $badges = [
            [
                'key' => 'channel',
                'label' => $this->channel_label,
                'palette' => 'sky',
            ],
            [
                'key' => 'mode',
                'label' => $this->currentOperationalModeLabel(),
                'palette' => $this->currentOperationalModePalette(),
            ],
            [
                'key' => 'status',
                'label' => ConversationStatus::tryFrom((string) $status)?->label() ?? Str::headline((string) $status),
                'palette' => 'slate',
            ],
        ];

        if ((int) ($this->merged_conversation_count ?? 1) > 1) {
            $badges[] = [
                'key' => 'merged',
                'label' => (int) $this->merged_conversation_count.' sesi digabung',
                'palette' => 'emerald',
            ];
        }

        if (filled($this->source_app)) {
            $badges[] = [
                'key' => 'source',
                'label' => 'Source: '.$this->source_label,
                'palette' => 'indigo',
            ];
        }

        if ($this->relationLoaded('assignedAdmin') && $this->assignedAdmin !== null) {
            $badges[] = [
                'key' => 'owner',
                'label' => 'Owner: '.$this->assignedAdmin->name,
                'palette' => 'indigo',
            ];
        }

        if ($this->needs_human && ! $this->isAdminTakeover() && $this->currentOperationalMode() !== 'closed') {
            $badges[] = [
                'key' => 'needs_human',
                'label' => 'Needs Human',
                'palette' => 'red',
            ];
        }

        if ((int) ($this->unread_messages_count ?? 0) > 0) {
            $badges[] = [
                'key' => 'unread',
                'label' => ((int) $this->unread_messages_count > 9 ? '9+' : (string) (int) $this->unread_messages_count).' unread',
                'palette' => 'red',
            ];
        }

        return $badges;
    }

    private function decoratedPreview(string $senderType, string $preview): string
    {
        $prefix = match ($senderType) {
            'bot' => 'Bot: ',
            'admin', 'agent' => 'Admin: ',
            'system' => 'System: ',
            default => '',
        };

        if ($preview === '') {
            return 'Belum ada preview pesan.';
        }

        return Str::limit($prefix.$preview, 120);
    }
}
