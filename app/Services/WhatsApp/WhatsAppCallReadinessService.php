<?php

namespace App\Services\WhatsApp;

class WhatsAppCallReadinessService
{
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

        $logDirectory = storage_path('logs');
        $emergencyLog = storage_path('logs/whatsapp-emergency.log');

        return [
            'ok' => $missing === [],
            'calling_enabled' => (bool) config('chatbot.whatsapp.calling.enabled', false),
            'config_complete' => $missing === [],
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
            'storage_logs_writable' => is_dir($logDirectory) && is_writable($logDirectory),
            'emergency_log_writable' => $this->canWriteEmergencyLog($emergencyLog),
            'app_url' => config('app.url'),
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
}
