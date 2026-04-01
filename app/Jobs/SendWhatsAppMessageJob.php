<?php

namespace App\Jobs;

use App\Enums\AuditActionType;
use App\Enums\ConversationChannel;
use App\Enums\MessageDirection;
use App\Enums\SenderType;
use App\Models\AdminNotification;
use App\Models\ConversationMessage;
use App\Services\Support\AuditLogService;
use App\Services\WhatsApp\WhatsAppMediaService;
use App\Services\WhatsApp\WhatsAppSenderService;
use App\Support\MediaUrlNormalizer;
use App\Support\WaLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class SendWhatsAppMessageJob implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    public int $tries = 3;
    public array $backoff = [30, 90, 300];
    public int $timeout = 60;

    public function __construct(
        public readonly int $conversationMessageId,
        public readonly string $traceId = '',
    ) {}

    public function handle(
        WhatsAppSenderService $sender,
        AuditLogService $audit,
        WhatsAppMediaService $mediaService,
    ): void
    {
        if ($this->traceId !== '') {
            WaLog::setTrace($this->traceId);
        }

        $jobStartMs = (int) round(microtime(true) * 1000);

        WaLog::info('[Job:SendWA] Started', [
            'message_id' => $this->conversationMessageId,
            'attempt' => $this->attempts(),
        ]);

        $message = ConversationMessage::with('conversation.customer')
            ->find($this->conversationMessageId);

        if ($message === null) {
            WaLog::warning('[Job:SendWA] Message not found', [
                'message_id' => $this->conversationMessageId,
            ]);

            return;
        }

        $senderType = is_string($message->sender_type) ? $message->sender_type : $message->sender_type?->value;

        if ($message->direction !== MessageDirection::Outbound) {
            WaLog::debug('[Job:SendWA] Skipped - not outbound', [
                'message_id' => $message->id,
            ]);

            return;
        }

        if (! in_array($message->sender_type, [SenderType::Bot, SenderType::Admin, SenderType::Agent], true)) {
            WaLog::debug('[Job:SendWA] Skipped - sender_type not bot/agent', [
                'message_id' => $message->id,
                'sender_type' => $senderType,
            ]);

            return;
        }

        if (! empty($message->wa_message_id)) {
            WaLog::debug('[Job:SendWA] Skipped - already has wa_message_id', [
                'message_id' => $message->id,
                'wa_message_id' => $message->wa_message_id,
            ]);

            return;
        }

        if ($message->delivery_status?->isTerminal()) {
            WaLog::debug('[Job:SendWA] Skipped - already in terminal delivery status', [
                'message_id' => $message->id,
                'delivery_status' => $message->delivery_status?->value,
            ]);

            return;
        }

        $message->incrementSendAttempt();

        $maxAttempts = config('chatbot.reliability.max_send_attempts', 3);
        if ($message->send_attempts > $maxAttempts) {
            $message->markSkipped('max_attempts_exceeded');

            $audit->record(AuditActionType::WhatsAppSendSkipped, [
                'auditable_type' => ConversationMessage::class,
                'auditable_id' => $message->id,
                'conversation_id' => $message->conversation_id,
                'message' => "Dilewati - melebihi batas maksimal percobaan ({$maxAttempts}).",
                'context' => [
                    'message_id' => $message->id,
                    'send_attempts' => $message->send_attempts,
                    'max_attempts' => $maxAttempts,
                ],
            ]);

            WaLog::warning('[Job:SendWA] Skipped - max send attempts exceeded', [
                'message_id' => $message->id,
                'send_attempts' => $message->send_attempts,
                'max_attempts' => $maxAttempts,
            ]);

            return;
        }

        if ($message->message_type === 'text' && empty($message->message_text)) {
            $message->markSkipped('empty_text');

            $audit->record(AuditActionType::WhatsAppSendSkipped, [
                'auditable_type' => ConversationMessage::class,
                'auditable_id' => $message->id,
                'conversation_id' => $message->conversation_id,
                'message' => 'Dilewati - teks pesan kosong.',
                'context' => [
                    'reason' => 'empty_text',
                    'message_id' => $message->id,
                ],
            ]);

            return;
        }

        $conversation = $message->conversation;
        $customer = $conversation?->customer;

        if (! $conversation?->isWhatsApp()) {
            $message->markSkipped('wrong_channel_dispatch', [
                'channel_delivery' => [
                    'expected' => ConversationChannel::WhatsApp->value,
                    'actual' => $conversation?->channel,
                ],
            ]);

            $audit->record(AuditActionType::WhatsAppSendSkipped, [
                'auditable_type' => ConversationMessage::class,
                'auditable_id' => $message->id,
                'conversation_id' => $message->conversation_id,
                'message' => 'Pengiriman WhatsApp dilewati karena channel percakapan bukan WhatsApp.',
                'context' => [
                    'reason' => 'wrong_channel_dispatch',
                    'message_id' => $message->id,
                    'channel' => $conversation?->channel,
                ],
            ]);

            return;
        }

        if ($customer === null || empty($customer->phone_e164)) {
            $message->markSkipped('no_valid_customer_phone');

            $audit->record(AuditActionType::WhatsAppSendSkipped, [
                'auditable_type' => ConversationMessage::class,
                'auditable_id' => $message->id,
                'conversation_id' => $message->conversation_id,
                'message' => 'Dilewati - customer atau nomor telepon tidak valid.',
                'context' => [
                    'reason' => 'no_valid_customer_phone',
                    'message_id' => $message->id,
                ],
            ]);

            WaLog::warning('[Job:SendWA] Skipped - no valid customer phone', [
                'message_id' => $message->id,
                'conversation_id' => $message->conversation_id,
            ]);

            return;
        }

        $audit->record(AuditActionType::WhatsAppSendAttempt, [
            'auditable_type' => ConversationMessage::class,
            'auditable_id' => $message->id,
            'conversation_id' => $conversation->id,
            'message' => "Mencoba mengirim pesan ke {$customer->phone_e164} (percobaan ke-{$message->send_attempts})",
            'context' => [
                'message_id' => $message->id,
                'sender_type' => $senderType,
                'phone' => $customer->phone_e164,
                'send_attempts' => $message->send_attempts,
                'text_preview' => $message->textPreview(80),
            ],
        ]);

        $result = $sender->sendMessage(
            toPhoneE164: $customer->phone_e164,
            text: (string) $message->message_text,
            messageType: $message->message_type,
            providerPayload: $this->providerPayloadForDispatch($message, $mediaService),
            meta: [
                'conversation_id' => $conversation->id,
                'message_id' => $message->id,
                'sender_type' => $senderType,
            ],
        );

        if ($result['status'] === 'sent') {
            $responsePayload = is_array($result['response'] ?? null) ? $result['response'] : [];
            $waMessageId = data_get($responsePayload, 'messages.0.id');
            $deliveryMeta = is_array($responsePayload['_delivery'] ?? null)
                ? $responsePayload['_delivery']
                : [];
            $sentType = (string) ($deliveryMeta['sent_type'] ?? $message->message_type);
            $fallbackUsed = (bool) (
                ($deliveryMeta['interactive_text_fallback_used'] ?? false)
                || ($deliveryMeta['reengagement_template_fallback_used'] ?? false)
            );
            $templateFallbackUsed = (bool) ($deliveryMeta['reengagement_template_fallback_used'] ?? false);

            $message->markSent($waMessageId, ['wa_send_result' => $result]);
            $message->forceFill([
                'channel_message_id' => $waMessageId,
            ])->save();

            $audit->record(AuditActionType::WhatsAppSendSuccess, [
                'auditable_type' => ConversationMessage::class,
                'auditable_id' => $message->id,
                'conversation_id' => $conversation->id,
                'message' => "Pesan berhasil dikirim ke {$customer->phone_e164}",
                'context' => [
                    'message_id' => $message->id,
                    'wa_message_id' => $waMessageId,
                    'send_attempts' => $message->send_attempts,
                    'sent_type' => $sentType,
                    'fallback_used' => $fallbackUsed,
                    'template_fallback_used' => $templateFallbackUsed,
                ],
            ]);

            WaLog::info('[Job:SendWA] Sent successfully', [
                'message_id' => $message->id,
                'wa_message_id' => $waMessageId,
                'send_attempts' => $message->send_attempts,
                'sent_type' => $sentType,
                'fallback_used' => $fallbackUsed,
                'template_fallback_used' => $templateFallbackUsed,
                'duration_ms' => (int) round(microtime(true) * 1000) - $jobStartMs,
            ]);

            if ((bool) ($deliveryMeta['interactive_text_fallback_used'] ?? false)) {
                WaLog::warning('[Job:SendWA] Interactive payload fell back to plain text', [
                    'message_id' => $message->id,
                    'requested_type' => $deliveryMeta['requested_type'] ?? $message->message_type,
                    'sent_type' => $sentType,
                    'error' => $deliveryMeta['interactive_error'] ?? null,
                ]);
            }

            if ($templateFallbackUsed) {
                WaLog::warning('[Job:SendWA] 24h session closed, message fell back to approved template', [
                    'message_id' => $message->id,
                    'requested_type' => $deliveryMeta['requested_type'] ?? $message->message_type,
                    'sent_type' => $sentType,
                    'error_code' => $deliveryMeta['reengagement_error_code'] ?? null,
                    'error' => $deliveryMeta['reengagement_error'] ?? null,
                ]);
            }

            return;
        }

        if ($result['status'] === 'skipped') {
            $message->markSkipped('sender_disabled', ['wa_send_result' => $result]);

            $audit->record(AuditActionType::WhatsAppSendSkipped, [
                'auditable_type' => ConversationMessage::class,
                'auditable_id' => $message->id,
                'conversation_id' => $conversation->id,
                'message' => 'Pengiriman dilewati - WhatsApp sender tidak aktif atau belum dikonfigurasi.',
                'context' => [
                    'message_id' => $message->id,
                    'reason' => 'sender_disabled',
                ],
            ]);

            WaLog::debug('[Job:SendWA] Skipped - sender not enabled', [
                'message_id' => $message->id,
            ]);

            return;
        }

        $errorText = $result['error'] ?? 'Unknown error';

        $message->markFailed($errorText, ['wa_send_result' => $result]);

        $audit->record(AuditActionType::WhatsAppSendFailure, [
            'auditable_type' => ConversationMessage::class,
            'auditable_id' => $message->id,
            'conversation_id' => $conversation->id,
            'message' => "Pengiriman WhatsApp gagal ke {$customer->phone_e164}: {$errorText}",
            'context' => [
                'message_id' => $message->id,
                'error' => $errorText,
                'status' => $result['status'],
                'send_attempts' => $message->send_attempts,
            ],
        ]);

        WaLog::warning('[Job:SendWA] Send failed', [
            'message_id' => $message->id,
            'status' => $result['status'],
            'error' => $errorText,
            'send_attempts' => $message->send_attempts,
        ]);

        if (
            config('chatbot.notifications.enabled', true)
            && config('chatbot.notifications.create_on_message_failed', true)
        ) {
            $this->createFailureNotification($message, $customer->phone_e164, $errorText, $conversation->id);
        }
    }

    public function failed(\Throwable $exception): void
    {
        WaLog::error('[Job:SendWA] Permanently failed after retries', [
            'message_id' => $this->conversationMessageId,
            'error' => $exception->getMessage(),
            'file' => $exception->getFile() . ':' . $exception->getLine(),
            'trace' => $exception->getTraceAsString(),
        ]);

        $message = ConversationMessage::find($this->conversationMessageId);
        if ($message !== null && ! $message->delivery_status?->isTerminal()) {
            $message->markFailed('Job failed permanently: ' . $exception->getMessage());
        }
    }

    private function createFailureNotification(
        ConversationMessage $message,
        string $customerPhone,
        string $error,
        int $conversationId,
    ): void {
        try {
            AdminNotification::create([
                'type' => 'whatsapp_failed',
                'title' => 'Pengiriman WhatsApp gagal',
                'body' => implode("\n", [
                    "Pesan ke {$customerPhone} gagal dikirim.",
                    'Percakapan  : #' . $conversationId,
                    'Percobaan ke: ' . $message->send_attempts,
                    'Pesan       : ' . $message->textPreview(120),
                    'Error       : ' . $error,
                ]),
                'payload' => [
                    'message_id' => $message->id,
                    'conversation_id' => $conversationId,
                    'customer_phone' => $customerPhone,
                    'error' => $error,
                    'send_attempts' => $message->send_attempts,
                ],
                'is_read' => false,
            ]);
        } catch (\Throwable $e) {
            WaLog::error('[Job:SendWA] Failed to create failure notification', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function providerPayloadForDispatch(
        ConversationMessage $message,
        WhatsAppMediaService $mediaService,
    ): array
    {
        $rawPayload = is_array($message->raw_payload) ? $message->raw_payload : [];
        $payload = is_array($rawPayload['outbound_payload'] ?? null)
            ? $rawPayload['outbound_payload']
            : [];

        if ($message->message_type === 'audio') {
            $audioPayload = is_array($payload['audio'] ?? null) ? $payload['audio'] : [];
            $publicAudioUrl = $this->publicMediaUrl($message, $rawPayload, 'audio');

            if ($publicAudioUrl !== null) {
                $audioPayload['link'] = $publicAudioUrl;
                unset($audioPayload['id']);
            }

            if ($audioPayload !== []) {
                $payload['audio'] = $audioPayload;
            }

            return $payload;
        }

        if ($message->message_type !== 'image') {
            return $payload;
        }

        $imagePayload = is_array($payload['image'] ?? null) ? $payload['image'] : [];
        $publicImageUrl = $this->publicImageUrl($message, $rawPayload, $mediaService);
        $uploadedImageId = $this->outboundImageMediaId($message, $rawPayload, $mediaService);

        if ($uploadedImageId !== null) {
            $imagePayload['id'] = $uploadedImageId;
            unset($imagePayload['link']);
            if ($publicImageUrl !== null) {
                $payload['_image_link_fallback'] = $publicImageUrl;
            }
        } elseif ($publicImageUrl !== null) {
            $imagePayload['link'] = $publicImageUrl;
            unset($imagePayload['id']);
        }

        if ($imagePayload !== []) {
            $payload['image'] = $imagePayload;
        }

        if (
            trim((string) ($payload['caption'] ?? '')) === ''
            && trim((string) data_get($rawPayload, 'media_caption', '')) !== ''
        ) {
            $payload['caption'] = (string) data_get($rawPayload, 'media_caption');
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $rawPayload
     */
    private function outboundImageMediaId(
        ConversationMessage $message,
        array $rawPayload,
        WhatsAppMediaService $mediaService,
    ): ?string {
        $cachedMediaId = trim((string) data_get($rawPayload, 'whatsapp_outbound_media_id', ''));
        if ($cachedMediaId !== '') {
            return $cachedMediaId;
        }

        [$storageDisk, $storagePath, $latestRawPayload] = $this->storedImageLocationForDispatch(
            $message,
            $rawPayload,
            $mediaService,
        );

        if ($storageDisk === '' || $storagePath === '' || ! Storage::disk($storageDisk)->exists($storagePath)) {
            return null;
        }

        try {
            $mimeType = trim((string) (
                Storage::disk($storageDisk)->mimeType($storagePath)
                ?: data_get($latestRawPayload, 'mime_type')
                ?: 'application/octet-stream'
            ));
            $fileName = trim((string) (
                data_get($latestRawPayload, 'media_original_name')
                ?: basename($storagePath)
            ));
            $uploadedMediaId = $mediaService->uploadFromContents(
                Storage::disk($storageDisk)->get($storagePath),
                $fileName,
                $mimeType,
            );

            $message->forceFill([
                'raw_payload' => array_merge($latestRawPayload, [
                    'whatsapp_outbound_media_id' => $uploadedMediaId,
                ]),
            ])->save();

            return $uploadedMediaId;
        } catch (\Throwable $e) {
            WaLog::warning('[Job:SendWA] Failed to upload stored image media for outbound dispatch', [
                'message_id' => $message->id,
                'storage_disk' => $storageDisk,
                'storage_path' => $storagePath,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $rawPayload
     */
    private function publicImageUrl(
        ConversationMessage $message,
        array $rawPayload,
        WhatsAppMediaService $mediaService,
    ): ?string
    {
        [$storageDisk, $storagePath] = $this->storedImageLocationForDispatch($message, $rawPayload, $mediaService);

        if ($storageDisk !== '' && $storagePath !== '') {
            return $this->temporarySignedMediaUrl($message)
                ?? MediaUrlNormalizer::normalize(Storage::disk($storageDisk)->url($storagePath));
        }

        $imageLink = trim((string) data_get($rawPayload, 'outbound_payload.image.link', ''));

        return $imageLink !== '' ? MediaUrlNormalizer::normalize($imageLink) : null;
    }

    private function publicMediaUrl(
        ConversationMessage $message,
        array $rawPayload,
        string $mediaType,
    ): ?string {
        $storageDisk = trim((string) data_get($rawPayload, 'media_storage_disk', ''));
        $storagePath = trim((string) data_get($rawPayload, 'media_storage_path', ''));

        if ($storageDisk !== '' && $storagePath !== '' && Storage::disk($storageDisk)->exists($storagePath)) {
            return $this->temporarySignedMediaUrl($message)
                ?? MediaUrlNormalizer::normalize(Storage::disk($storageDisk)->url($storagePath));
        }

        $directLink = trim((string) data_get($rawPayload, 'outbound_payload.'.$mediaType.'.link', ''));
        return $directLink !== '' ? MediaUrlNormalizer::normalize($directLink) : null;
    }

    private function temporarySignedMediaUrl(ConversationMessage $message): ?string
    {
        try {
            return URL::temporarySignedRoute(
                'api.admin-mobile.media.show',
                now()->addHours(6),
                ['message' => $message->id],
            );
        } catch (\Throwable $e) {
            WaLog::warning('[Job:SendWA] Failed to generate signed image fallback URL', [
                'message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $rawPayload
     * @return array{0: string, 1: string, 2: array<string, mixed>}
     */
    private function storedImageLocationForDispatch(
        ConversationMessage $message,
        array $rawPayload,
        WhatsAppMediaService $mediaService,
    ): array {
        $imageId = trim((string) (
            data_get($rawPayload, 'image.id')
            ?? data_get($rawPayload, 'outbound_payload.image.id')
            ?? ''
        ));

        $storageDisk = trim((string) data_get($rawPayload, 'media_storage_disk', ''));
        $storagePath = trim((string) data_get($rawPayload, 'media_storage_path', ''));
        $latestRawPayload = $rawPayload;

        if (($storageDisk === '' || $storagePath === '') && $imageId !== '') {
            [$storageDisk, $storagePath] = $this->cacheRemoteImageForDispatch(
                $message,
                $rawPayload,
                $mediaService,
                $imageId,
            );

            $freshMessage = $message->fresh();
            $latestRawPayload = is_array($freshMessage?->raw_payload) ? $freshMessage->raw_payload : $rawPayload;
        }

        return [$storageDisk, $storagePath, $latestRawPayload];
    }

    /**
     * @param  array<string, mixed>  $rawPayload
     * @return array{0: string, 1: string}
     */
    private function cacheRemoteImageForDispatch(
        ConversationMessage $message,
        array $rawPayload,
        WhatsAppMediaService $mediaService,
        string $imageId,
    ): array {
        try {
            $download = $mediaService->downloadByMediaId($imageId);
            $safeFileName = Str::slug(pathinfo($download['file_name'], PATHINFO_FILENAME));
            $extension = pathinfo($download['file_name'], PATHINFO_EXTENSION);
            $storedFileName = trim($safeFileName) !== ''
                ? $safeFileName.'.'.$extension
                : $message->id.'.'.$extension;
            $storedPath = 'conversation-media/images/outbound/'.$message->id.'-'.$storedFileName;

            Storage::disk('public')->put($storedPath, $download['contents']);

            $message->forceFill([
                'raw_payload' => array_merge($rawPayload, [
                    'media_storage_disk' => 'public',
                    'media_storage_path' => $storedPath,
                    'mime_type' => (string) ($download['mime_type'] ?? data_get($rawPayload, 'mime_type')),
                    'media_original_name' => (string) ($download['file_name'] ?? basename($storedPath)),
                    'media_size_bytes' => (int) ($download['size_bytes'] ?? 0),
                ]),
            ])->save();

            return ['public', $storedPath];
        } catch (\Throwable $e) {
            WaLog::warning('[Job:SendWA] Failed to cache image media for outbound dispatch', [
                'message_id' => $message->id,
                'image_id' => $imageId,
                'error' => $e->getMessage(),
            ]);

            return ['', ''];
        }
    }
}
