<?php

namespace App\Services\Firebase;

use App\Models\DeviceFcmToken;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Service untuk mengirim push notification via Firebase Cloud Messaging (FCM) v1 HTTP API.
 *
 * Implementasi ini TIDAK membutuhkan package kreait/firebase karena menggunakan
 * FCM v1 HTTP API langsung + Google OAuth2 service account authentication.
 * Ini menghindari masalah kompatibilitas package di shared hosting.
 *
 * Alur:
 * 1. Baca service account JSON → buat JWT → tukar ke access token Google OAuth2
 * 2. Kirim POST ke https://fcm.googleapis.com/v1/projects/{projectId}/messages:send
 */
class FcmService
{
    private ?array $serviceAccount = null;

    // ─── Public API ───────────────────────────────────────────────────────

    /**
     * Kirim push notification ke SEMUA device admin yang punya role admin/operator.
     * Digunakan saat ada pesan WhatsApp masuk ke chatbot.
     */
    public function notifyAllAdmins(
        string $title,
        string $body,
        array $data = [],
    ): array {
        $tokens = DeviceFcmToken::query()
            ->where('is_active', true)
            ->whereHas('user', function ($q): void {
                $q->where('is_chatbot_admin', true)
                  ->orWhere('is_chatbot_operator', true);
            })
            ->get();

        if ($tokens->isEmpty()) {
            return ['sent' => 0, 'failed' => 0, 'reason' => 'no_active_tokens'];
        }

        $sent = 0;
        $failed = 0;

        foreach ($tokens as $tokenModel) {
            $success = $this->sendToToken(
                fcmToken: $tokenModel->fcm_token,
                title: $title,
                body: $body,
                data: $data,
            );

            if ($success) {
                $tokenModel->recordSuccess();
                $sent++;
            } else {
                $tokenModel->recordFailure();
                $failed++;
            }
        }

        return ['sent' => $sent, 'failed' => $failed];
    }

    /**
     * Kirim push notification ke device milik admin tertentu.
     */
    public function notifyUser(
        int $userId,
        string $title,
        string $body,
        array $data = [],
    ): array {
        $tokens = DeviceFcmToken::query()
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->get();

        if ($tokens->isEmpty()) {
            return ['sent' => 0, 'failed' => 0, 'reason' => 'no_active_tokens'];
        }

        $sent = 0;
        $failed = 0;

        foreach ($tokens as $tokenModel) {
            $success = $this->sendToToken(
                fcmToken: $tokenModel->fcm_token,
                title: $title,
                body: $body,
                data: $data,
            );

            if ($success) {
                $tokenModel->recordSuccess();
                $sent++;
            } else {
                $tokenModel->recordFailure();
                $failed++;
            }
        }

        return ['sent' => $sent, 'failed' => $failed];
    }

    // ─── FCM v1 HTTP API ──────────────────────────────────────────────────

    /**
     * Kirim satu notifikasi ke satu FCM token via FCM v1 HTTP API.
     */
    private function sendToToken(
        string $fcmToken,
        string $title,
        string $body,
        array $data = [],
    ): bool {
        $projectId = $this->getProjectId();
        $accessToken = $this->getAccessToken();

        if ($projectId === '' || $accessToken === '') {
            Log::warning('[FcmService] Cannot send — missing project ID or access token');
            return false;
        }

        $url = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";

        // Pastikan semua value di data adalah string (FCM requirement).
        $stringData = [];
        foreach ($data as $key => $value) {
            $stringData[(string) $key] = (string) $value;
        }

        $payload = [
            'message' => [
                'token' => $fcmToken,
                'notification' => [
                    'title' => $title,
                    'body' => $body,
                ],
                'data' => $stringData,
                'android' => [
                    'priority' => 'high',
                    'notification' => [
                        'channel_id' => 'whatjet_chat_messages',
                        'sound' => 'default',
                        'default_vibrate_timings' => true,
                        'notification_count' => (int) ($data['unread_count'] ?? 1),
                    ],
                ],
            ],
        ];

        try {
            $response = Http::withToken($accessToken)
                ->timeout(10)
                ->post($url, $payload);

            if ($response->successful()) {
                return true;
            }

            $errorBody = $response->json();
            $errorCode = $errorBody['error']['code'] ?? $response->status();
            $errorStatus = $errorBody['error']['status'] ?? '';

            // Token tidak valid lagi (user uninstall app, token expired, dll).
            if (in_array($errorStatus, ['NOT_FOUND', 'UNREGISTERED', 'INVALID_ARGUMENT'], true)) {
                Log::info('[FcmService] Token invalid/unregistered — will be deactivated', [
                    'error_status' => $errorStatus,
                ]);
                return false;
            }

            Log::warning('[FcmService] FCM send failed', [
                'status' => $response->status(),
                'error_code' => $errorCode,
                'error_status' => $errorStatus,
                'body' => $errorBody,
            ]);

            return false;
        } catch (\Throwable $e) {
            Log::error('[FcmService] HTTP exception while sending push', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    // ─── Google OAuth2 Service Account Auth ────────────────────────────────

    /**
     * Dapatkan access token OAuth2 dari Google menggunakan service account JWT.
     * Token di-cache selama 50 menit (OAuth2 token berlaku 60 menit).
     */
    private function getAccessToken(): string
    {
        return Cache::remember('fcm_oauth2_access_token', 3000, function (): string {
            $sa = $this->loadServiceAccount();
            if ($sa === null) {
                return '';
            }

            $jwt = $this->createJwt($sa);
            if ($jwt === '') {
                return '';
            }

            try {
                $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $jwt,
                ]);

                if ($response->successful()) {
                    return (string) ($response->json('access_token') ?? '');
                }

                Log::error('[FcmService] OAuth2 token exchange failed', [
                    'status' => $response->status(),
                    'body' => $response->json(),
                ]);

                return '';
            } catch (\Throwable $e) {
                Log::error('[FcmService] OAuth2 HTTP error', [
                    'error' => $e->getMessage(),
                ]);
                return '';
            }
        });
    }

    /**
     * Buat JWT (JSON Web Token) untuk ditukar ke access token Google OAuth2.
     * Menggunakan service account private key (RS256).
     */
    private function createJwt(array $sa): string
    {
        $privateKey = $sa['private_key'] ?? '';
        $clientEmail = $sa['client_email'] ?? '';

        if ($privateKey === '' || $clientEmail === '') {
            Log::error('[FcmService] Invalid service account — missing private_key or client_email');
            return '';
        }

        $now = time();

        $header = $this->base64UrlEncode(json_encode([
            'alg' => 'RS256',
            'typ' => 'JWT',
        ]));

        $payload = $this->base64UrlEncode(json_encode([
            'iss' => $clientEmail,
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ]));

        $signingInput = "{$header}.{$payload}";

        $key = openssl_pkey_get_private($privateKey);
        if ($key === false) {
            Log::error('[FcmService] Cannot parse service account private key');
            return '';
        }

        $signature = '';
        $signResult = openssl_sign($signingInput, $signature, $key, OPENSSL_ALGO_SHA256);

        if (! $signResult) {
            Log::error('[FcmService] openssl_sign failed');
            return '';
        }

        return "{$signingInput}.{$this->base64UrlEncode($signature)}";
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    // ─── Config & Service Account ─────────────────────────────────────────

    private function getProjectId(): string
    {
        // Coba dari config dulu.
        $configProjectId = trim((string) config('firebase.project_id', ''));
        if ($configProjectId !== '') {
            return $configProjectId;
        }

        // Fallback: baca dari service account JSON.
        $sa = $this->loadServiceAccount();
        return (string) ($sa['project_id'] ?? '');
    }

    /**
     * Load dan parse service account JSON file.
     * Lokasi file dikonfigurasi di config/firebase.php → credentials_file.
     */
    private function loadServiceAccount(): ?array
    {
        if ($this->serviceAccount !== null) {
            return $this->serviceAccount;
        }

        $path = config('firebase.credentials_file', '');
        if ($path === '' || ! is_string($path)) {
            Log::error('[FcmService] config firebase.credentials_file not set');
            return null;
        }

        // Support path relatif ke base_path() dan path absolut.
        $resolvedPath = str_starts_with($path, '/') ? $path : base_path($path);

        if (! file_exists($resolvedPath)) {
            Log::error('[FcmService] Service account file not found', [
                'path' => $resolvedPath,
            ]);
            return null;
        }

        $content = file_get_contents($resolvedPath);
        if ($content === false) {
            Log::error('[FcmService] Cannot read service account file');
            return null;
        }

        $decoded = json_decode($content, true);
        if (! is_array($decoded)) {
            Log::error('[FcmService] Service account file is not valid JSON');
            return null;
        }

        $this->serviceAccount = $decoded;
        return $decoded;
    }
}