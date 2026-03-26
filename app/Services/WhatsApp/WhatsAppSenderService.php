<?php

namespace App\Services\WhatsApp;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppSenderService
{
    private readonly string $accessToken;
    private readonly string $phoneNumberId;
    private readonly string $graphBaseUrl;
    private readonly int    $timeoutSeconds;
    private readonly bool   $enabled;

    public function __construct()
    {
        $this->accessToken    = (string) config('chatbot.whatsapp.access_token', '');
        $this->phoneNumberId  = (string) config('chatbot.whatsapp.phone_number_id', '');
        $this->graphBaseUrl   = rtrim((string) config('chatbot.whatsapp.graph_base_url', 'https://graph.facebook.com/v19.0'), '/');
        $this->timeoutSeconds = (int)    config('chatbot.whatsapp.send_timeout_seconds', 15);
        $this->enabled        = (bool)   config('chatbot.whatsapp.enabled', false);
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

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
     * Send a plain text message to a WhatsApp recipient.
     *
     * @param  string               $toPhoneE164  Recipient phone in E.164 format (e.g. +628123456789)
     * @param  string               $text         Message body
     * @param  array<string, mixed> $meta         Optional contextual data added to the log
     *
     * @return array{status: string, provider: string, response: array|null, error: string|null}
     */
    public function sendText(string $toPhoneE164, string $text, array $meta = []): array
    {
        if (! $this->isEnabled()) {
            Log::debug('[WhatsAppSender] Skipped — sender not enabled', [
                'to'      => $toPhoneE164,
                'preview' => mb_substr($text, 0, 60),
            ]);

            return $this->result('skipped');
        }

        // WhatsApp Cloud API expects the phone number WITHOUT the leading '+'.
        $to = ltrim($toPhoneE164, '+');

        $endpoint = "{$this->graphBaseUrl}/{$this->phoneNumberId}/messages";

        $payload = [
            'messaging_product' => 'whatsapp',
            'to'                => $to,
            'type'              => 'text',
            'text'              => ['body' => $text],
        ];

        try {
            $response = Http::withToken($this->accessToken)
                ->timeout($this->timeoutSeconds)
                ->post($endpoint, $payload);

            if ($response->successful()) {
                Log::info('[WhatsAppSender] Message sent', array_merge([
                    'to'          => $toPhoneE164,
                    'wa_id'       => $response->json('messages.0.id'),
                ], $meta));

                return $this->result('sent', $response->json());
            }

            Log::warning('[WhatsAppSender] Send failed — HTTP error', [
                'to'          => $toPhoneE164,
                'http_status' => $response->status(),
                'body'        => $response->body(),
            ]);

            return $this->result('failed', $response->json(), $response->body());
        } catch (\Throwable $e) {
            Log::error('[WhatsAppSender] Send exception: ' . $e->getMessage(), [
                'to' => $toPhoneE164,
            ]);

            return $this->result('error', null, $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Build a consistent result array.
     *
     * @param  array<string, mixed>|null  $response
     * @return array{status: string, provider: string, response: array|null, error: string|null}
     */
    private function result(
        string $status,
        ?array $response = null,
        ?string $error = null,
    ): array {
        return [
            'status'   => $status,
            'provider' => 'whatsapp',
            'response' => $response,
            'error'    => $error,
        ];
    }
}
