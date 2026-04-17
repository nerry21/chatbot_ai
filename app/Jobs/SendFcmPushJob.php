<?php

namespace App\Jobs;

use App\Models\ConversationMessage;
use App\Services\Firebase\FcmNotificationService;
use App\Support\WaLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Job untuk mengirim FCM push notification ke admin secara asynchronous.
 *
 * ALASAN KEBERADAAN JOB INI:
 * ----------------------------------------------------------------
 * Sebelumnya, FcmNotificationService::notifyIncomingMessage() dipanggil
 * SINKRON di dalam WhatsAppWebhookService. Ini menyebabkan:
 *   - Webhook menunggu HTTP call ke Google OAuth2 + Firebase FCM (±1–3 detik)
 *   - Bila Firebase lambat/down, webhook ikut lambat → Meta retry → pesan duplikat
 *
 * Dengan dispatch job ini:
 *   - Webhook langsung return 200 OK ke Meta (< 1 detik)
 *   - Push notification dikerjakan worker di background
 *   - Bila gagal, di-retry otomatis tanpa mempengaruhi pemrosesan pesan bot
 *
 * PENTING: Job ini hanya carry primitive (message id). Semua data
 * diambil ulang di handle() untuk menghindari masalah serialization
 * dari model Eloquent yang punya relasi kompleks.
 */
class SendFcmPushJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Maximum attempts before the job is marked as permanently failed. */
    public int $tries = 3;

    /** @var array<int, int> Backoff dalam detik: 10s, 30s, 90s. */
    public array $backoff = [10, 30, 90];

    /** Timeout per attempt (detik). */
    public int $timeout = 30;

    public function __construct(
        public readonly int $messageId,
        public readonly string $traceId = '',
    ) {}

    public function handle(FcmNotificationService $fcmNotificationService): void
    {
        // Restore trace id dari webhook asal agar log bisa di-trace.
        if ($this->traceId !== '') {
            WaLog::setTrace($this->traceId);
        }

        /** @var ConversationMessage|null $message */
        $message = ConversationMessage::query()
            ->with(['conversation.customer'])
            ->find($this->messageId);

        if ($message === null) {
            WaLog::warning('[Job:SendFcmPush] Message not found, skipping', [
                'message_id' => $this->messageId,
            ]);
            return;
        }

        $conversation = $message->conversation;
        if ($conversation === null) {
            WaLog::warning('[Job:SendFcmPush] Conversation not found, skipping', [
                'message_id' => $this->messageId,
            ]);
            return;
        }

        $fcmNotificationService->notifyIncomingMessage(
            message: $message,
            conversation: $conversation,
            customer: $conversation->customer,
        );
    }

    /**
     * Dipanggil Laravel saat job benar-benar gagal setelah semua retry habis.
     * Push notification yang gagal final cukup di-log; tidak boleh mengganggu
     * apa pun karena bot sudah memproses pesan dengan sukses.
     */
    public function failed(\Throwable $e): void
    {
        WaLog::warning('[Job:SendFcmPush] Failed after all retries', [
            'message_id' => $this->messageId,
            'error' => $e->getMessage(),
        ]);
    }
}