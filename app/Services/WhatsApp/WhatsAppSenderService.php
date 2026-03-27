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

    public function isEnabled(): bool
    {
        return $this->enabled
            && $this->accessToken !== ''
            && $this->phoneNumberId !== '';
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array{status: string, provider: string, response: array|null, error: string|null}
     */
    public function sendText(string $toPhoneE164, string $text, array $meta = []): array
    {
        return $this->sendMessage($toPhoneE164, $text, 'text', [], $meta);
    }

    /**
     * @param  array<string, mixed>  $providerPayload
     * @param  array<string, mixed>  $meta
     * @return array{status: string, provider: string, response: array|null, error: string|null, requested_type?: string, sent_type?: string, fallback_used?: bool}
     */
    public function sendMessage(
        string $toPhoneE164,
        string $text,
        string $messageType = 'text',
        array $providerPayload = [],
        array $meta = [],
    ): array {
        $normalizedE164 = $this->phoneService->toE164($toPhoneE164);
        $to = $this->phoneService->toDigits($toPhoneE164);
        $resolvedType = $this->resolveMessageType($messageType, $providerPayload);

        if (! $this->isEnabled()) {
            WaLog::info('[Sender] Skipped sender_disabled', [
                'to' => WaLog::maskPhone($toPhoneE164),
                'normalized_to' => $to,
                'preview' => mb_substr($text, 0, 60),
            ]);

            return $this->result('skipped');
        }

        if ($normalizedE164 === '' || ! $this->phoneService->isValidE164($normalizedE164) || $to === '') {
            WaLog::warning('[Sender] Skipped invalid recipient phone number', array_merge([
                'input_to' => $toPhoneE164,
                'normalized_e164' => $normalizedE164,
                'normalized_to' => $to,
            ], $meta));

            return $this->result('failed', null, 'Invalid recipient phone number.');
        }

        $endpoint = "{$this->graphBaseUrl}/{$this->phoneNumberId}/messages";
        $requestBody = $this->buildRequestBody($to, $text, $resolvedType, $providerPayload);

        WaLog::info('[Sender] Sending message', array_merge([
            'to' => WaLog::maskPhone($normalizedE164),
            'message_type' => $resolvedType,
            'preview' => mb_substr($text, 0, 80),
        ], $meta));

        $startMs = (int) round(microtime(true) * 1000);

        try {
            $response = Http::withToken($this->accessToken)
                ->timeout($this->timeoutSeconds)
                ->post($endpoint, $requestBody);

            $durationMs = (int) round(microtime(true) * 1000) - $startMs;

            if ($response->successful()) {
                WaLog::info('[Sender] Message sent successfully', array_merge([
                    'to' => WaLog::maskPhone($normalizedE164),
                    'http_status' => $response->status(),
                    'duration_ms' => $durationMs,
                ], $meta));

                return $this->result('sent', $response->json());
            }

            $errorMsg = $response->json('error.message') ?? $response->body();

            if ($this->shouldFallbackInteractiveToText($resolvedType, $response->status(), (string) $errorMsg)) {
                WaLog::warning('[Sender] Interactive send rejected, retrying as text fallback', array_merge([
                    'to' => WaLog::maskPhone($normalizedE164),
                    'http_status' => $response->status(),
                    'error_msg' => mb_substr((string) $errorMsg, 0, 300),
                    'duration_ms' => $durationMs,
                ], $meta));

                $fallbackResult = $this->sendMessage(
                    toPhoneE164: $toPhoneE164,
                    text: $text,
                    messageType: 'text',
                    providerPayload: [],
                    meta: array_merge($meta, ['fallback_from' => 'interactive']),
                );

                $fallbackResponse = is_array($fallbackResult['response'] ?? null)
                    ? $fallbackResult['response']
                    : [];
                $fallbackResponse['_delivery'] = [
                    'requested_type' => 'interactive',
                    'sent_type' => $fallbackResult['status'] === 'sent' ? 'text' : ($fallbackResult['sent_type'] ?? 'text'),
                    'interactive_text_fallback_used' => true,
                    'interactive_http_status' => $response->status(),
                    'interactive_error' => (string) $errorMsg,
                ];
                $fallbackResult['response'] = $fallbackResponse;
                $fallbackResult['requested_type'] = 'interactive';
                $fallbackResult['sent_type'] = $fallbackResult['status'] === 'sent' ? 'text' : ($fallbackResult['sent_type'] ?? 'text');
                $fallbackResult['fallback_used'] = true;

                return $fallbackResult;
            }

            WaLog::warning('[Sender] Send failed', array_merge([
                'to' => WaLog::maskPhone($normalizedE164),
                'http_status' => $response->status(),
                'error_msg' => mb_substr((string) $errorMsg, 0, 300),
                'duration_ms' => $durationMs,
            ], $meta));

            return $this->result('failed', $response->json(), (string) $errorMsg);
        } catch (\Throwable $e) {
            WaLog::error('[Sender] Exception during HTTP send', array_merge([
                'to' => WaLog::maskPhone($normalizedE164 !== '' ? $normalizedE164 : $toPhoneE164),
                'error' => $e->getMessage(),
            ], $meta));

            return $this->result('error', null, $e->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>|null  $response
     * @return array{status: string, provider: string, response: array|null, error: string|null}
     */
    private function result(string $status, ?array $response = null, ?string $error = null): array
    {
        return [
            'status' => $status,
            'provider' => 'whatsapp',
            'response' => $response,
            'error' => $error,
        ];
    }

    /**
     * @param  array<string, mixed>  $providerPayload
     * @return array<string, mixed>
     */
    private function buildRequestBody(string $to, string $text, string $messageType, array $providerPayload): array
    {
        if ($messageType === 'interactive') {
            return [
                'messaging_product' => 'whatsapp',
                'to' => $to,
                'type' => 'interactive',
                'interactive' => $providerPayload['interactive'] ?? [],
            ];
        }

        return [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'text',
            'text' => ['body' => $text],
        ];
    }

    /**
     * @param  array<string, mixed>  $providerPayload
     */
    private function resolveMessageType(string $messageType, array $providerPayload): string
    {
        if (
            $messageType === 'interactive'
            && (bool) config('chatbot.whatsapp.interactive_enabled', true)
            && isset($providerPayload['interactive'])
        ) {
            return 'interactive';
        }

        return 'text';
    }

    private function shouldFallbackInteractiveToText(string $resolvedType, int $httpStatus, string $errorMessage): bool
    {
        if ($resolvedType !== 'interactive') {
            return false;
        }

        if (! (bool) config('chatbot.whatsapp.interactive_text_fallback_enabled', true)) {
            return false;
        }

        if (! in_array($httpStatus, [400, 422], true)) {
            return false;
        }

        $normalized = mb_strtolower(trim($errorMessage), 'UTF-8');

        if ($normalized === '') {
            return true;
        }

        foreach ([
            'interactive',
            'button',
            'list',
            'unsupported',
            'not supported',
            'invalid',
            'parameter',
        ] as $keyword) {
            if (str_contains($normalized, $keyword)) {
                return true;
            }
        }

        return false;
    }
}
