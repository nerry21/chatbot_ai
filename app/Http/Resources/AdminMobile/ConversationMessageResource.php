<?php

namespace App\Http\Resources\AdminMobile;

use App\Enums\MessageDirection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\ConversationMessage */
class ConversationMessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $direction = is_string($this->direction) ? $this->direction : $this->direction?->value;
        $senderType = is_string($this->sender_type) ? $this->sender_type : $this->sender_type?->value;
        $deliveryStatus = is_string($this->delivery_status) ? $this->delivery_status : $this->delivery_status?->value;
        $senderUser = $this->relationLoaded('senderUser') ? $this->senderUser : null;
        $interactivePayload = data_get($this->raw_payload, 'outbound_payload.interactive');
        $interactiveSelection = data_get($this->raw_payload, '_interactive_selection');
        $audioLink = data_get($this->raw_payload, 'outbound_payload.audio.link')
            ?? data_get($this->raw_payload, 'audio.link')
            ?? data_get($this->raw_payload, 'audio_url');
        $audioId = data_get($this->raw_payload, 'audio.id');

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
            'sender_label' => $this->senderLabel($senderType),
            'sender_user_id' => $this->sender_user_id,
            'sender_name' => $this->senderName($senderType, $senderUser?->name),
            'message_type' => $this->message_type,
            'message_text' => $this->message_text,
            'client_message_id' => $this->client_message_id,
            'channel_message_id' => $this->channel_message_id,
            'delivery_status' => $deliveryStatus,
            'delivery_label' => $this->deliveryLabel($deliveryStatus),
            'delivery_error' => $this->delivery_error,
            'is_delivered_to_app' => $this->delivered_to_app_at !== null,
            'is_read_by_customer' => $this->read_at !== null,
            'is_fallback' => (bool) $this->is_fallback,
            'ai_intent' => $this->ai_intent,
            'read_at' => $this->read_at?->toIso8601String(),
            'delivered_to_app_at' => $this->delivered_to_app_at?->toIso8601String(),
            'sent_at' => $this->sent_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'interactive' => [
                'type' => is_array($interactivePayload) ? ($interactivePayload['type'] ?? null) : null,
                'selection' => is_array($interactiveSelection) ? $interactiveSelection : null,
                'button_options' => collect(data_get($interactivePayload, 'action.buttons', []))
                    ->map(fn (array $button): ?string => data_get($button, 'reply.title'))
                    ->filter()
                    ->values()
                    ->all(),
                'list_options' => collect(data_get($interactivePayload, 'action.sections', []))
                    ->flatMap(fn (array $section): array => $section['rows'] ?? [])
                    ->map(fn (array $row): ?string => $row['title'] ?? null)
                    ->filter()
                    ->values()
                    ->all(),
                'is_booking_review' => filled(data_get($this->raw_payload, 'review_hash')),
            ],
            'media' => [
                'audio_url' => $audioLink,
                'audio_id' => $audioId,
                'mime_type' => data_get($this->raw_payload, 'mime_type'),
                'caption' => data_get($this->raw_payload, 'media_caption'),
                'is_voice_note' => (bool) (data_get($this->raw_payload, 'outbound_payload.audio.voice')
                    ?? data_get($this->raw_payload, 'audio.voice')
                    ?? ($this->message_type === 'audio')),
            ],
        ];
    }

    private function senderLabel(?string $senderType): string
    {
        return match ($senderType) {
            'customer' => 'Customer',
            'admin', 'agent' => 'Admin',
            'system' => 'System',
            default => 'Bot',
        };
    }

    private function senderName(?string $senderType, ?string $senderUserName): ?string
    {
        if (filled($senderUserName)) {
            return $senderUserName;
        }

        if (in_array($senderType, ['admin', 'agent'], true)) {
            return data_get($this->raw_payload, 'admin_name');
        }

        return null;
    }

    private function deliveryLabel(?string $deliveryStatus): ?string
    {
        return match ($deliveryStatus) {
            'pending' => 'sending',
            'sent', 'delivered' => $this->read_at
                ? 'read'
                : ($this->delivered_to_app_at ? 'delivered' : 'sent'),
            'failed' => 'failed',
            'skipped' => 'skipped',
            default => $deliveryStatus,
        };
    }
}
