<?php

namespace App\Services\Firebase;

use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Customer;
use App\Models\DeviceFcmToken;
use Illuminate\Support\Facades\Log;

/**
 * Service level tinggi untuk mengirim notifikasi chat masuk ke admin.
 *
 * Dipanggil dari WhatsAppWebhookService setelah pesan berhasil di-persist.
 * Service ini:
 *   1. Menentukan title & body notifikasi berdasarkan data customer/message
 *   2. Menghitung unread count untuk badge
 *   3. Memanggil FcmService untuk kirim ke semua device admin
 *
 * PENTING: Method ini harus cepat (non-blocking). Kalau gagal, pesan
 * tetap tersimpan di database — notifikasi hanya "best effort".
 */
class FcmNotificationService
{
    public function __construct(
        private readonly FcmService $fcmService,
    ) {}

    /**
     * Kirim push notification ke semua admin saat ada pesan WhatsApp masuk.
     *
     * @param ConversationMessage $message  Pesan yang baru di-persist
     * @param Conversation        $conversation
     * @param Customer|null       $customer
     */
    public function notifyIncomingMessage(
        ConversationMessage $message,
        Conversation $conversation,
        ?Customer $customer = null,
    ): void {
        // Cek apakah fitur FCM aktif.
        if (! config('firebase.notification.enabled', true)) {
            return;
        }

        // Cek apakah ada device token terdaftar sama sekali.
        if (DeviceFcmToken::query()->where('is_active', true)->count() === 0) {
            return;
        }

        try {
            $senderName = $this->resolveSenderName($customer, $conversation);
            $previewText = $this->buildPreviewText($message);
            $unreadCount = $this->countTotalUnread();

            $result = $this->fcmService->notifyAllAdmins(
                title: $senderName,
                body: $previewText,
                data: [
                    'type' => 'incoming_message',
                    'conversation_id' => (string) $conversation->id,
                    'message_id' => (string) $message->id,
                    'customer_id' => (string) ($customer->id ?? ''),
                    'sender_name' => $senderName,
                    'sender_phone' => (string) ($customer->phone_e164 ?? ''),
                    'channel' => (string) ($conversation->channel ?? 'whatsapp'),
                    'unread_count' => (string) $unreadCount,
                    'timestamp' => now()->toIso8601String(),
                ],
            );

            Log::info('[FcmNotification] Push sent for incoming message', [
                'conversation_id' => $conversation->id,
                'message_id' => $message->id,
                'sender' => $senderName,
                'result' => $result,
            ]);
        } catch (\Throwable $e) {
            // Push notification gagal TIDAK boleh mengganggu flow webhook.
            Log::warning('[FcmNotification] Failed to send push — non-fatal', [
                'conversation_id' => $conversation->id,
                'message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Tentukan nama pengirim yang ditampilkan di notifikasi.
     */
    private function resolveSenderName(?Customer $customer, Conversation $conversation): string
    {
        // Coba dari customer name.
        if ($customer !== null && filled($customer->name)) {
            return (string) $customer->name;
        }

        // Coba dari customer phone.
        if ($customer !== null && filled($customer->phone_e164)) {
            return (string) $customer->phone_e164;
        }

        // Fallback ke conversation title atau generic.
        return 'Pesan WhatsApp Baru';
    }

    /**
     * Buat preview text singkat dari pesan untuk body notifikasi.
     */
    private function buildPreviewText(ConversationMessage $message): string
    {
        $type = (string) ($message->message_type ?? $message->type ?? 'text');

        // Untuk pesan text, tampilkan isi (potong max 150 karakter).
        $body = (string) ($message->body ?? $message->message_text ?? '');

        if ($body !== '') {
            $cleaned = trim(strip_tags($body));
            if (mb_strlen($cleaned, 'UTF-8') > 150) {
                return mb_substr($cleaned, 0, 147, 'UTF-8') . '...';
            }
            return $cleaned;
        }

        // Untuk tipe non-text, tampilkan ikon + label.
        return match ($type) {
            'image'    => '📷 Foto',
            'video'    => '🎥 Video',
            'audio', 'voice' => '🎵 Audio',
            'document' => '📄 Dokumen',
            'location' => '📍 Lokasi',
            'contact', 'contacts' => '👤 Kontak',
            'sticker'  => '🏷️ Stiker',
            default    => 'Pesan baru',
        };
    }

    /**
     * Hitung total unread messages di semua conversation aktif.
     * Digunakan untuk badge count di icon launcher app.
     */
    private function countTotalUnread(): int
    {
        try {
            return (int) Conversation::query()
                ->where('status', 'active')
                ->sum('unread_count');
        } catch (\Throwable) {
            // Kolom unread_count mungkin tidak ada di semua setup.
            // Fallback: hitung conversation yang punya pesan baru.
            try {
                return (int) Conversation::query()
                    ->where('status', 'active')
                    ->where('last_message_at', '>=', now()->subDay())
                    ->count();
            } catch (\Throwable) {
                return 1;
            }
        }
    }
}