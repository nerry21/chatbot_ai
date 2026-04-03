<?php

namespace App\Http\Resources\AdminMobile;

use App\Enums\MessageDirection;
use App\Support\MediaUrlNormalizer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;

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
        $senderUser = $this->relationLoaded('senderUser') ? $this->senderUser : null;
        $interactivePayload = data_get($this->raw_payload, 'outbound_payload.interactive');
        $interactiveSelection = data_get($this->raw_payload, '_interactive_selection');
        $audioLink = data_get($this->raw_payload, 'outbound_payload.audio.link')
            ?? data_get($this->raw_payload, 'audio.link')
            ?? data_get($this->raw_payload, 'audio_url');

        $imageLink = data_get($this->raw_payload, 'outbound_payload.image.link')
            ?? data_get($this->raw_payload, 'image.link')
            ?? data_get($this->raw_payload, 'image_url');

        $videoLink = data_get($this->raw_payload, 'outbound_payload.video.link')
            ?? data_get($this->raw_payload, 'video.link')
            ?? data_get($this->raw_payload, 'video_url');

        $documentLink = data_get($this->raw_payload, 'outbound_payload.document.link')
            ?? data_get($this->raw_payload, 'document.link')
            ?? data_get($this->raw_payload, 'document_url');

        $audioId = data_get($this->raw_payload, 'audio.id')
            ?? data_get($this->raw_payload, 'outbound_payload.audio.id');

        $imageId = data_get($this->raw_payload, 'image.id')
            ?? data_get($this->raw_payload, 'outbound_payload.image.id');

        $videoId = data_get($this->raw_payload, 'video.id')
            ?? data_get($this->raw_payload, 'outbound_payload.video.id');

        $documentId = data_get($this->raw_payload, 'document.id')
            ?? data_get($this->raw_payload, 'outbound_payload.document.id');

        $normalizedAudioLink = $this->signedMediaUrl('audio')
            ?? MediaUrlNormalizer::normalize(is_string($audioLink) ? $audioLink : null);

        $normalizedImageLink = $this->signedMediaUrl('image')
            ?? MediaUrlNormalizer::normalize(is_string($imageLink) ? $imageLink : null);

        $normalizedVideoLink = $this->signedMediaUrl('video')
            ?? MediaUrlNormalizer::normalize(is_string($videoLink) ? $videoLink : null);

        $normalizedDocumentLink = $this->signedMediaUrl('document')
            ?? MediaUrlNormalizer::normalize(is_string($documentLink) ? $documentLink : null);

        if ($direction === MessageDirection::Inbound->value && in_array($deliveryStatus, [null, 'pending'], true)) {
            $deliveryStatus = 'sent';
        }

        $deliveryLabel = $this->deliveryLabel($deliveryStatus);

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
            'wa_message_id' => $this->wa_message_id,
            'delivery_status' => $deliveryStatus,
            'delivery_label' => $deliveryLabel,
            'status_label' => $deliveryLabel,
            'delivery_error' => $this->delivery_error,
            'is_delivered_to_app' => $this->delivered_to_app_at !== null,
            'is_read_by_customer' => $this->read_at !== null,
            'is_fallback' => (bool) $this->is_fallback,
            'ai_intent' => $this->ai_intent,
            'read_at' => $this->read_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'delivered_to_app_at' => $this->delivered_to_app_at?->toIso8601String(),
            'sent_at' => $this->sent_at?->toIso8601String(),
            'failed_at' => $this->failed_at?->toIso8601String(),
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
                'image_url' => $normalizedImageLink,
                'image_id' => $imageId,
                'audio_url' => $normalizedAudioLink,
                'audio_id' => $audioId,
                'video_url' => $normalizedVideoLink,
                'video_id' => $videoId,
                'document_url' => $normalizedDocumentLink,
                'document_id' => $documentId,
                'mime_type' => data_get($this->raw_payload, 'mime_type')
                    ?? data_get($this->raw_payload, 'image.mime_type')
                    ?? data_get($this->raw_payload, 'video.mime_type')
                    ?? data_get($this->raw_payload, 'document.mime_type'),
                'caption' => data_get($this->raw_payload, 'media_caption')
                    ?? data_get($this->raw_payload, 'image.caption')
                    ?? data_get($this->raw_payload, 'video.caption')
                    ?? data_get($this->raw_payload, 'document.caption'),
                'original_name' => data_get($this->raw_payload, 'media_original_name')
                    ?? data_get($this->raw_payload, 'document.filename')
                    ?? data_get($this->raw_payload, 'video.filename'),
                'size_bytes' => data_get($this->raw_payload, 'media_size_bytes')
                    ?? data_get($this->raw_payload, 'image.file_size')
                    ?? data_get($this->raw_payload, 'video.file_size')
                    ?? data_get($this->raw_payload, 'document.file_size'),
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
        if ($deliveryStatus === 'failed') {
            return 'failed';
        }

        if ($this->read_at !== null) {
            return 'read';
        }

        if (
            $deliveryStatus === 'delivered'
            || $this->delivered_at !== null
            || $this->delivered_to_app_at !== null
        ) {
            return 'delivered';
        }

        return match ($deliveryStatus) {
            'pending' => 'sending',
            'sent' => 'sent',
            'skipped' => 'skipped',
            default => $deliveryStatus,
        };
    }

    private function signedMediaUrl(string $type): ?string
    {
        $storageDisk = trim((string) data_get($this->raw_payload, 'media_storage_disk', ''));
        $storagePath = trim((string) data_get($this->raw_payload, 'media_storage_path', ''));
        $mediaId = trim((string) (
            data_get($this->raw_payload, $type.'.id')
            ?? data_get($this->raw_payload, 'outbound_payload.'.$type.'.id')
            ?? ''
        ));

        if (
            $this->message_type !== $type
            || (($storageDisk === '' || $storagePath === '') && $mediaId === '')
        ) {
            return null;
        }

        return MediaUrlNormalizer::normalize(
            URL::signedRoute('api.admin-mobile.media.show', ['message' => $this->id]),
        );
    }
}
