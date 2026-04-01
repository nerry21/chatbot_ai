<?php

namespace App\Jobs;

use App\Enums\AuditActionType;
use App\Enums\ConversationChannel;
use App\Enums\MessageDirection;
use App\Enums\SenderType;
use App\Models\AdminNotification;
use App\Models\ConversationMessage;
use App\Services\Support\AuditLogService;
use App\Services\WhatsApp\WhatsAppSenderService;
use App\Support\MediaUrlNormalizer;
use App\Support\WaLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;

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

    public function handle(WhatsAppSenderService $sender, AuditLogService $audit): void
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
            providerPayload: $this->providerPayloadForDispatch($message),
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
    private function providerPayloadForDispatch(ConversationMessage $message): array
    {
        $rawPayload = is_array($message->raw_payload) ? $message->raw_payload : [];
        $payload = is_array($rawPayload['outbound_payload'] ?? null)
            ? $rawPayload['outbound_payload']
            : [];

        if ($message->message_type !== 'image') {
            return $payload;
        }

        $imagePayload = is_array($payload['image'] ?? null) ? $payload['image'] : [];
        $signedImageUrl = $this->signedImageUrl($message, $rawPayload);

        if ($signedImageUrl !== null) {
            $imagePayload['link'] = $signedImageUrl;
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
    private function signedImageUrl(ConversationMessage $message, array $rawPayload): ?string
    {
        $storageDisk = trim((string) data_get($rawPayload, 'media_storage_disk', ''));
        $storagePath = trim((string) data_get($rawPayload, 'media_storage_path', ''));
        $imageId = trim((string) (
            data_get($rawPayload, 'image.id')
            ?? data_get($rawPayload, 'outbound_payload.image.id')
            ?? ''
        ));

        if ($storageDisk === '' && $storagePath === '' && $imageId === '') {
            return null;
        }

        return MediaUrlNormalizer::normalize(
            URL::signedRoute('api.admin-mobile.media.show', ['message' => $message->id]),
        );
    }
}
