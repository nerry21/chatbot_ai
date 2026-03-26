<?php

namespace App\Services\WhatsApp;

use App\Services\Support\PhoneNumberService;
use App\Support\WaLog;
use Illuminate\Support\Facades\Http;

class WhatsAppSenderService
{
    private readonly string $accessToken;
    private readonly string $phoneNumberId;
    private readonly string $graphBaseUrl;
    private readonly int $timeoutSeconds;
    private readonly bool $enabled;

    public function __construct(
        private readonly PhoneNumberService $phoneService,
    ) {
        $this->accessToken = (string) config('chatbot.whatsapp.access_token', '');
        $this->phoneNumberId = (string) config('chatbot.whatsapp.phone_number_id', '');
        $this->graphBaseUrl = rtrim((string) config('chatbot.whatsapp.graph_base_url', 'https://graph.facebook.com/v19.0'), '/');
        $this->timeoutSeconds = (int) config('chatbot.whatsapp.send_timeout_seconds', 15);
        $this->enabled = (bool) config('chatbot.whatsapp.enabled', false);
    }

    // ─── Public API ───────────────────────────────────────────────────────────

    /**
     * Returns true only when the integration is switched on, a valid token
     * exists, and a phone number ID is configured.
     */
    public function isEnabled(): bool
    {
        return $this->enabled
            && $this->accessToken !== ''
            && $this->phoneNumberId !== '';
    }

    /**
     * Send a plain-text message to a WhatsApp recipient.
     *
     * @param  string               $toPhoneE164  Recipient phone in E.164 format
     * @param  string               $text         Message body
     * @param  array<string, mixed> $meta         Optional context added to logs
     *
     * @return array{status: string, provider: string, response: array|null, error: string|null}
     */
    public function sendText(string $toPhoneE164, string $text, array $meta = []): array
    {
        $normalizedE164 = $this->phoneService->toE164($toPhoneE164);
        $to = $this->phoneService->toDigits($toPhoneE164);

        if (! $this->isEnabled()) {
            WaLog::info('[Sender] Skipped — sender not enabled or misconfigured', [
                'to' => WaLog::maskPhone($toPhoneE164),
                'normalized_to' => $to,
                'preview' => mb_substr($text, 0, 60),
                'enabled_flag' => $this->enabled,
                'has_token' => $this->accessToken !== '',
                'has_phone_id' => $this->phoneNumberId !== '',
            ]);

            return $this->result('skipped');
        }

        if ($normalizedE164 === '' || ! $this->phoneService->isValidE164($normalizedE164) || $to === '') {
            WaLog::warning('[Sender] Skipped — invalid recipient phone number', array_merge([
                'input_to' => $toPhoneE164,
                'normalized_e164' => $normalizedE164,
                'normalized_to' => $to,
                'preview' => mb_substr($text, 0, 80),
            ], $meta));

            return $this->result('failed', null, 'Invalid recipient phone number.');
        }

        $endpoint = "{$this->graphBaseUrl}/{$this->phoneNumberId}/messages";

        WaLog::info('[Sender] Sending message to Meta', array_merge([
            'to' => WaLog::maskPhone($normalizedE164),
            'normalized_to' => $to,
            'preview' => mb_substr($text, 0, 80),
            'endpoint' => $this->graphBaseUrl . '/{phone_id}/messages',
            'timeout_s' => $this->timeoutSeconds,
        ], $meta));

        $startMs = (int) round(microtime(true) * 1000);

        try {
            $response = Http::withToken($this->accessToken)
                ->timeout($this->timeoutSeconds)
                ->post($endpoint, [
                    'messaging_product' => 'whatsapp',
                    'to' => $to,
                    'type' => 'text',
                    'text' => [
                        'body' => $text,
                    ],
                ]);

            $durationMs = (int) round(microtime(true) * 1000) - $startMs;

            if ($response->successful()) {
                $waId = $response->json('messages.0.id');

                WaLog::info('[Sender] Message sent successfully', array_merge([
                    'to' => WaLog::maskPhone($normalizedE164),
                    'normalized_to' => $to,
                    'wa_id' => $waId,
                    'http_status' => $response->status(),
                    'duration_ms' => $durationMs,
                    'response' => $response->json(),
                ], $meta));

                return $this->result('sent', $response->json());
            }

            $errorCode = $response->json('error.code');
            $errorMsg = $response->json('error.message') ?? $response->body();

            WaLog::warning('[Sender] Send failed — HTTP error from Meta', array_merge([
                'to' => WaLog::maskPhone($normalizedE164),
                'normalized_to' => $to,
                'http_status' => $response->status(),
                'error_code' => $errorCode,
                'error_msg' => mb_substr((string) $errorMsg, 0, 300),
                'duration_ms' => $durationMs,
                'response' => $response->json(),
            ], $meta));

            return $this->result('failed', $response->json(), (string) $errorMsg);
        } catch (\Throwable $e) {
            $durationMs = (int) round(microtime(true) * 1000) - $startMs;

            WaLog::error('[Sender] Exception during HTTP send', array_merge([
                'to' => WaLog::maskPhone($normalizedE164 !== '' ? $normalizedE164 : $toPhoneE164),
                'normalized_to' => $to,
                'error' => $e->getMessage(),
                'file' => $e->getFile() . ':' . $e->getLine(),
                'duration_ms' => $durationMs,
                'trace' => $e->getTraceAsString(),
            ], $meta));

            return $this->result('error', null, $e->getMessage());
        }
    }

    // ─── Private ──────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>|null  $response
     * @return array{status: string, provider: string, response: array|null, error: string|null}
     */
    private function result(
        string $status,
        ?array $response = null,
        ?string $error = null,
    ): array {
        return [
            'status' => $status,
            'provider' => 'whatsapp',
            'response' => $response,
            'error' => $error,
        ];
    }
}