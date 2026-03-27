<?php

namespace App\Jobs;

use App\Enums\AuditActionType;
use App\Enums\MessageDeliveryStatus;
use App\Enums\MessageDirection;
use App\Enums\SenderType;
use App\Models\AdminNotification;
use App\Models\ConversationMessage;
use App\Services\Support\AuditLogService;
use App\Services\WhatsApp\WhatsAppSenderService;
use App\Support\WaLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendWhatsAppMessageJob implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    public int   $tries   = 3;
    public array $backoff = [30, 90, 300];
    public int   $timeout = 60;

    public function __construct(
        public readonly int    $conversationMessageId,
        public readonly string $traceId = '',
    ) {}

    public function handle(WhatsAppSenderService $sender, AuditLogService $audit): void
    {
        // Restore trace ID from parent job/request
        if ($this->traceId !== '') {
            WaLog::setTrace($this->traceId);
        }

        $jobStartMs = (int) round(microtime(true) * 1000);

        WaLog::info('[Job:SendWA] Started', [
            'message_id' => $this->conversationMessageId,
            'attempt'    => $this->attempts(),
        ]);

        $message = ConversationMessage::with('conversation.customer')
            ->find($this->conversationMessageId);

        if ($message === null) {
            WaLog::warning('[Job:SendWA] Message not found', [
                'message_id' => $this->conversationMessageId,
            ]);
            return;
        }

        // ── Static guards — direction / sender_type / idempotency ───────────

        if ($message->direction !== MessageDirection::Outbound) {
            WaLog::debug('[Job:SendWA] Skipped — not outbound', [
                'message_id' => $message->id,
            ]);
            return;
        }

        if (! in_array($message->sender_type, [SenderType::Bot, SenderType::Agent], true)) {
            WaLog::debug('[Job:SendWA] Skipped — sender_type not bot/agent', [
                'message_id'  => $message->id,
                'sender_type' => $message->sender_type->value,
            ]);
            return;
        }

        // Idempotent guard: already successfully sent
        if (! empty($message->wa_message_id)) {
            WaLog::debug('[Job:SendWA] Skipped — already has wa_message_id', [
                'message_id'    => $message->id,
                'wa_message_id' => $message->wa_message_id,
            ]);
            return;
        }

        // Idempotent guard: already in terminal delivery status
        if ($message->delivery_status?->isTerminal()) {
            WaLog::debug('[Job:SendWA] Skipped — already in terminal delivery status', [
                'message_id'      => $message->id,
                'delivery_status' => $message->delivery_status?->value,
            ]);
            return;
        }

        // ── Track this attempt (Tahap 9) ─────────────────────────────────────

        $message->incrementSendAttempt();

        // Hard cap: if send_attempts now exceeds the configured maximum, give up.
        // This prevents runaway retries if someone keeps resending without
        // resetting the counter through proper channels.
        $maxAttempts = config('chatbot.reliability.max_send_attempts', 3);
        if ($message->send_attempts > $maxAttempts) {
            $message->markSkipped('max_attempts_exceeded');

            $audit->record(AuditActionType::WhatsAppSendSkipped, [
                'auditable_type'  => ConversationMessage::class,
                'auditable_id'    => $message->id,
                'conversation_id' => $message->conversation_id,
                'message'         => "Dilewati — melebihi batas maksimal percobaan ({$maxAttempts}).",
                'context'         => [
                    'message_id'   => $message->id,
                    'send_attempts'=> $message->send_attempts,
                    'max_attempts' => $maxAttempts,
                ],
            ]);

            WaLog::warning('[Job:SendWA] Skipped — max send attempts exceeded', [
                'message_id'    => $message->id,
                'send_attempts' => $message->send_attempts,
                'max_attempts'  => $maxAttempts,
            ]);
            return;
        }

        // ── Content guards ───────────────────────────────────────────────────

        if (empty($message->message_text)) {
            $message->markSkipped('empty_text');
            $audit->record(AuditActionType::WhatsAppSendSkipped, [
                'auditable_type'  => ConversationMessage::class,
                'auditable_id'    => $message->id,
                'conversation_id' => $message->conversation_id,
                'message'         => 'Dilewati — teks pesan kosong.',
                'context'         => ['reason' => 'empty_text', 'message_id' => $message->id],
            ]);
            return;
        }

        $conversation = $message->conversation;
        $customer     = $conversation?->customer;

        if ($customer === null || empty($customer->phone_e164)) {
            $message->markSkipped('no_valid_customer_phone');
            $audit->record(AuditActionType::WhatsAppSendSkipped, [
                'auditable_type'  => ConversationMessage::class,
                'auditable_id'    => $message->id,
                'conversation_id' => $message->conversation_id,
                'message'         => 'Dilewati — customer atau nomor telepon tidak valid.',
                'context'         => ['reason' => 'no_valid_customer_phone', 'message_id' => $message->id],
            ]);
            WaLog::warning('[Job:SendWA] Skipped — no valid customer phone', [
                'message_id'      => $message->id,
                'conversation_id' => $message->conversation_id,
            ]);
            return;
        }

        // ── Attempt to send ──────────────────────────────────────────────────

        $audit->record(AuditActionType::WhatsAppSendAttempt, [
            'auditable_type'  => ConversationMessage::class,
            'auditable_id'    => $message->id,
            'conversation_id' => $conversation->id,
            'message'         => "Mencoba mengirim pesan ke {$customer->phone_e164} (percobaan ke-{$message->send_attempts})",
            'context'         => [
                'message_id'    => $message->id,
                'sender_type'   => $message->sender_type->value,
                'phone'         => $customer->phone_e164,
                'send_attempts' => $message->send_attempts,
                'text_preview'  => $message->textPreview(80),
            ],
        ]);

        $result = $sender->sendMessage(
            toPhoneE164: $customer->phone_e164,
            text: $message->message_text,
            messageType: $message->message_type,
            providerPayload: is_array($message->raw_payload['outbound_payload'] ?? null)
                ? $message->raw_payload['outbound_payload']
                : [],
            meta: [
                'conversation_id' => $conversation->id,
                'message_id'      => $message->id,
                'sender_type'     => $message->sender_type->value,
            ],
        );

        // ── Handle result ────────────────────────────────────────────────────

        if ($result['status'] === 'sent') {
            $waMessageId = $result['response']['messages'][0]['id'] ?? null;
            $deliveryMeta = is_array($result['response']['_delivery'] ?? null)
                ? $result['response']['_delivery']
                : [];
            $sentType = (string) ($deliveryMeta['sent_type'] ?? $message->message_type);
            $fallbackUsed = (bool) ($deliveryMeta['interactive_text_fallback_used'] ?? false);

            $message->markSent($waMessageId, ['wa_send_result' => $result]);

            $audit->record(AuditActionType::WhatsAppSendSuccess, [
                'auditable_type'  => ConversationMessage::class,
                'auditable_id'    => $message->id,
                'conversation_id' => $conversation->id,
                'message'         => "Pesan berhasil dikirim ke {$customer->phone_e164}",
                'context'         => [
                    'message_id'    => $message->id,
                    'wa_message_id' => $waMessageId,
                    'send_attempts' => $message->send_attempts,
                    'sent_type'     => $sentType,
                    'fallback_used' => $fallbackUsed,
                ],
            ]);

            WaLog::info('[Job:SendWA] Sent successfully', [
                'message_id'    => $message->id,
                'wa_message_id' => $waMessageId,
                'send_attempts' => $message->send_attempts,
                'sent_type'     => $sentType,
                'fallback_used' => $fallbackUsed,
                'duration_ms'   => (int) round(microtime(true) * 1000) - $jobStartMs,
            ]);

            if ($fallbackUsed) {
                WaLog::warning('[Job:SendWA] Interactive payload fell back to plain text', [
                    'message_id' => $message->id,
                    'requested_type' => $deliveryMeta['requested_type'] ?? $message->message_type,
                    'sent_type' => $sentType,
                    'error' => $deliveryMeta['interactive_error'] ?? null,
                ]);
            }

        } elseif ($result['status'] === 'skipped') {
            $message->markSkipped('sender_disabled', ['wa_send_result' => $result]);

            $audit->record(AuditActionType::WhatsAppSendSkipped, [
                'auditable_type'  => ConversationMessage::class,
                'auditable_id'    => $message->id,
                'conversation_id' => $conversation->id,
                'message'         => 'Pengiriman dilewati — WhatsApp sender tidak aktif atau belum dikonfigurasi.',
                'context'         => ['message_id' => $message->id, 'reason' => 'sender_disabled'],
            ]);

            WaLog::debug('[Job:SendWA] Skipped — sender not enabled', [
                'message_id' => $message->id,
            ]);

        } else {
            // 'failed' or 'error'
            $errorText = $result['error'] ?? 'Unknown error';

            $message->markFailed($errorText, ['wa_send_result' => $result]);

            $audit->record(AuditActionType::WhatsAppSendFailure, [
                'auditable_type'  => ConversationMessage::class,
                'auditable_id'    => $message->id,
                'conversation_id' => $conversation->id,
                'message'         => "Pengiriman WhatsApp gagal ke {$customer->phone_e164}: {$errorText}",
                'context'         => [
                    'message_id'    => $message->id,
                    'error'         => $errorText,
                    'status'        => $result['status'],
                    'send_attempts' => $message->send_attempts,
                ],
            ]);

            WaLog::warning('[Job:SendWA] Send failed', [
                'message_id'    => $message->id,
                'status'        => $result['status'],
                'error'         => $errorText,
                'send_attempts' => $message->send_attempts,
            ]);

            if (
                config('chatbot.notifications.enabled', true)
                && config('chatbot.notifications.create_on_message_failed', true)
            ) {
                $this->createFailureNotification($message, $customer->phone_e164, $errorText, $conversation->id);
            }
        }
    }

    public function failed(\Throwable $exception): void
    {
        WaLog::error('[Job:SendWA] Permanently failed after retries', [
            'message_id' => $this->conversationMessageId,
            'error'      => $exception->getMessage(),
            'file'       => $exception->getFile() . ':' . $exception->getLine(),
            'trace'      => $exception->getTraceAsString(),
        ]);

        $message = ConversationMessage::find($this->conversationMessageId);
        if ($message !== null && ! $message->delivery_status?->isTerminal()) {
            $message->markFailed('Job failed permanently: ' . $exception->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function createFailureNotification(
        ConversationMessage $message,
        string $customerPhone,
        string $error,
        int $conversationId,
    ): void {
        try {
            AdminNotification::create([
                'type'    => 'whatsapp_failed',
                'title'   => 'Pengiriman WhatsApp gagal',
                'body'    => implode("\n", [
                    "Pesan ke {$customerPhone} gagal dikirim.",
                    'Percakapan  : #' . $conversationId,
                    'Percobaan ke: ' . $message->send_attempts,
                    'Pesan       : ' . $message->textPreview(120),
                    'Error       : ' . $error,
                ]),
                'payload' => [
                    'message_id'      => $message->id,
                    'conversation_id' => $conversationId,
                    'customer_phone'  => $customerPhone,
                    'error'           => $error,
                    'send_attempts'   => $message->send_attempts,
                ],
                'is_read' => false,
            ]);
        } catch (\Throwable $e) {
            WaLog::error('[Job:SendWA] Failed to create failure notification', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
