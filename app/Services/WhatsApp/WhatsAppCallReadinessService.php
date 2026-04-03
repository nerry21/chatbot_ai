<?php

namespace App\Services\WhatsApp;

class WhatsAppCallReadinessService
{
    public function __construct(
        private readonly MetaWhatsAppCallingApiService $metaCallingApiService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $baseUrl = trim((string) config('chatbot.whatsapp.calling.base_url', ''));
        $apiVersion = trim((string) config('chatbot.whatsapp.calling.api_version', ''));
        $accessToken = trim((string) config('chatbot.whatsapp.calling.access_token', ''));
        $phoneNumberId = trim((string) config('chatbot.whatsapp.calling.phone_number_id', ''));
        $signatureEnabled = (bool) config('chatbot.whatsapp.calling.webhook_signature_enabled', false);
        $webhookSecret = trim((string) config('chatbot.whatsapp.webhook_secret', ''));
        $verifyToken = trim((string) config('services.whatsapp.verify_token', ''));

        $missing = [];
        if (! (bool) config('chatbot.whatsapp.calling.enabled', false)) {
            $missing[] = 'WHATSAPP_CALLING_ENABLED=false';
        }
        if ($baseUrl === '' || filter_var($baseUrl, FILTER_VALIDATE_URL) === false) {
            $missing[] = 'WHATSAPP_CALLING_BASE_URL';
        }
        if ($apiVersion === '') {
            $missing[] = 'WHATSAPP_CALLING_API_VERSION';
        }
        if ($accessToken === '') {
            $missing[] = 'WHATSAPP_CALLING_ACCESS_TOKEN/WHATSAPP_ACCESS_TOKEN';
        }
        if ($phoneNumberId === '') {
            $missing[] = 'WHATSAPP_CALLING_PHONE_NUMBER_ID/WHATSAPP_PHONE_NUMBER_ID';
        }
        if ($signatureEnabled && $webhookSecret === '') {
            $missing[] = 'WHATSAPP_WEBHOOK_SECRET';
        }
        if ($verifyToken === '') {
            $missing[] = 'WHATSAPP_VERIFY_TOKEN';
        }

        $remoteSettings = $phoneNumberId !== ''
            ? $this->metaCallingApiService->getPhoneNumberSettings($phoneNumberId)
            : [];

        $remoteCallingEnabled = $this->resolveRemoteCallingEnabled($remoteSettings);

        $logDirectory = storage_path('logs');
        $emergencyLog = storage_path('logs/whatsapp-emergency.log');
        $callingEnabled = (bool) config('chatbot.whatsapp.calling.enabled', false);
        $configComplete = $missing === [];
        $isReady = $configComplete && $remoteCallingEnabled !== false;
        $storageLogsWritable = is_dir($logDirectory) && is_writable($logDirectory);
        $remoteSettingsOk = (bool) ($remoteSettings['ok'] ?? false);
        $remoteSettingsError = data_get($remoteSettings, 'error.message');

        return [
            'ok' => $isReady,
            'status' => [
                'label' => $isReady ? 'Calling Ready' : 'Not Ready',
                'color' => $isReady ? 'green' : 'red',
            ],
            'calling_enabled' => $callingEnabled,
            'config_complete' => $configComplete,
            'missing' => $missing,
            'base_url' => $baseUrl !== '' ? $baseUrl : null,
            'api_version' => $apiVersion !== '' ? $apiVersion : null,
            'retry_enabled' => (bool) config('chatbot.whatsapp.calling.retry_enabled', true),
            'max_retries' => (int) config('chatbot.whatsapp.calling.max_retries', 2),
            'permission_cooldown_seconds' => (int) config('chatbot.whatsapp.calling.permission_cooldown_seconds', 120),
            'rate_limit_cooldown_seconds' => (int) config('chatbot.whatsapp.calling.rate_limit_cooldown_seconds', 180),
            'dedup_enabled' => (bool) config('chatbot.whatsapp.calling.dedup_enabled', true),
            'webhook_signature_enabled' => $signatureEnabled,
            'storage_logs_exists' => is_dir($logDirectory),
            'storage_logs_writable' => $storageLogsWritable,
            'emergency_log_writable' => $this->canWriteEmergencyLog($emergencyLog),
            'app_url' => config('app.url'),
            'remote_settings_ok' => $remoteSettingsOk,
            'remote_calling_enabled' => $remoteCallingEnabled,
            'remote_settings_error' => $remoteSettingsError,
            'checks' => [
                [
                    'key' => 'backend_calling_flag',
                    'label' => 'Backend calling enabled',
                    'ok' => $callingEnabled,
                    'message' => $callingEnabled
                        ? 'WHATSAPP_CALLING_ENABLED aktif.'
                        : 'WHATSAPP_CALLING_ENABLED masih false.',
                ],
                [
                    'key' => 'config_complete',
                    'label' => 'Konfigurasi backend lengkap',
                    'ok' => $configComplete,
                    'message' => $configComplete
                        ? 'Semua konfigurasi dasar backend tersedia.'
                        : 'Masih ada konfigurasi yang belum lengkap: '.implode(', ', $missing),
                ],
                [
                    'key' => 'remote_settings',
                    'label' => 'Meta call settings dapat diakses',
                    'ok' => $remoteSettingsOk,
                    'message' => $remoteSettingsOk
                        ? 'Settings nomor WhatsApp berhasil dibaca dari Meta.'
                        : ($remoteSettingsError ?: 'Gagal membaca settings nomor WhatsApp dari Meta.'),
                ],
                [
                    'key' => 'remote_calling_enabled',
                    'label' => 'Calling aktif di nomor WhatsApp',
                    'ok' => $remoteCallingEnabled === true,
                    'message' => $remoteCallingEnabled === true
                        ? 'Calling sudah aktif di nomor WhatsApp ini.'
                        : ($remoteCallingEnabled === false
                            ? 'Calling API belum aktif pada nomor WhatsApp ini.'
                            : 'Status calling pada nomor belum dapat dipastikan.'),
                ],
                [
                    'key' => 'log_storage',
                    'label' => 'Storage logs writable',
                    'ok' => $storageLogsWritable,
                    'message' => $storageLogsWritable
                        ? 'Folder storage/logs tersedia dan dapat ditulis.'
                        : 'Folder storage/logs belum siap ditulis.',
                ],
            ],
        ];
    }

    private function canWriteEmergencyLog(string $path): bool
    {
        if (file_exists($path)) {
            return is_writable($path);
        }

        $directory = dirname($path);

        return is_dir($directory) && is_writable($directory);
    }

    private function resolveRemoteCallingEnabled(array $settings): ?bool
    {
        $raw = is_array($settings['raw'] ?? null) ? $settings['raw'] : [];

        foreach ([
            data_get($raw, 'calling.enabled'),
            data_get($raw, 'calling.voice.enabled'),
            data_get($raw, 'voice_calling.enabled'),
            data_get($raw, 'call_settings.calling.enabled'),
            $raw['calling_enabled'] ?? null,
        ] as $candidate) {
            if (is_bool($candidate)) {
                return $candidate;
            }

            $normalized = strtolower(trim((string) $candidate));
            if (in_array($normalized, ['1', 'true', 'enabled', 'active', 'on'], true)) {
                return true;
            }

            if (in_array($normalized, ['0', 'false', 'disabled', 'inactive', 'off'], true)) {
                return false;
            }
        }

        $message = strtolower(trim((string) data_get($settings, 'error.message', '')));
        if (
            str_contains($message, 'calling api not enabled')
            || str_contains($message, 'calling is not enabled')
            || str_contains($message, 'not enabled for this phone number')
        ) {
            return false;
        }

        return null;
    }
}
