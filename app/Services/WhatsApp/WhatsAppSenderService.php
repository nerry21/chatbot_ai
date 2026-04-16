<?php

namespace App\Services\WhatsApp;

use App\Services\Support\PhoneNumberService;
use App\Support\WaLog;
use Illuminate\Support\Arr;
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
     * @param array<string, mixed> $meta
     * @return array{status:string,provider:string,response:array|null,error:string|null}
     */
    public function sendText(string $toPhoneE164, string $text, array $meta = []): array
    {
        return $this->sendMessage($toPhoneE164, $text, 'text', [], $meta);
    }

    /**
     * @param array<string, mixed> $list
     * @param array<string, mixed> $meta
     * @return array{status:string,provider:string,response:array|null,error:string|null,requested_type?:string,sent_type?:string,fallback_used?:bool}
     */
    public function sendInteractiveList(string $toPhoneE164, array $list, array $meta = []): array
    {
        $interactive = [
            'type' => 'list',
            'body' => [
                'text' => (string) ($list['body'] ?? 'Silakan pilih salah satu.'),
            ],
            'action' => [
                'button' => (string) ($list['button'] ?? 'Pilih'),
                'sections' => (array) ($list['sections'] ?? []),
            ],
        ];

        $headerText = trim((string) ($list['header'] ?? ''));
        if ($headerText !== '') {
            $interactive['header'] = [
                'type' => 'text',
                'text' => $headerText,
            ];
        }

        $footerText = trim((string) ($list['footer'] ?? ''));
        if ($footerText !== '') {
            $interactive['footer'] = [
                'text' => $footerText,
            ];
        }

        return $this->sendMessage(
            $toPhoneE164,
            (string) ($list['body'] ?? 'Silakan pilih salah satu.'),
            'interactive',
            ['interactive' => $interactive],
            $meta,
        );
    }

    /**
     * @param array<string, mixed> $providerPayload
     * @param array<string, mixed> $meta
     * @return array{status:string,provider:string,response:array|null,error:string|null,requested_type?:string,sent_type?:string,fallback_used?:bool}
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

            $errorMsg = (string) ($response->json('error.message') ?? $response->body());
            $errorCode = (int) ($response->json('error.code') ?? 0);

            if ($this->shouldFallbackInteractiveToText($resolvedType, $response->status(), $errorMsg, $meta)) {
                WaLog::warning('[Sender] Interactive send rejected, retrying as text fallback', array_merge([
                    'to' => WaLog::maskPhone($normalizedE164),
                    'http_status' => $response->status(),
                    'error_msg' => mb_substr($errorMsg, 0, 300),
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
                    'interactive_error' => $errorMsg,
                ];
                $fallbackResult['response'] = $fallbackResponse;
                $fallbackResult['requested_type'] = 'interactive';
                $fallbackResult['sent_type'] = $fallbackResult['status'] === 'sent' ? 'text' : ($fallbackResult['sent_type'] ?? 'text');
                $fallbackResult['fallback_used'] = true;

                return $fallbackResult;
            }

            $audioFallbackResult = $this->retryAudioWithLinkFallback(
                toPhoneE164: $toPhoneE164,
                text: $text,
                resolvedType: $resolvedType,
                providerPayload: $providerPayload,
                responseStatus: $response->status(),
                errorMessage: $errorMsg,
                meta: $meta,
            );

            if ($audioFallbackResult !== null) {
                return $audioFallbackResult;
            }

            $imageFallbackResult = $this->retryImageWithLinkFallback(
                toPhoneE164: $toPhoneE164,
                text: $text,
                resolvedType: $resolvedType,
                providerPayload: $providerPayload,
                responseStatus: $response->status(),
                errorMessage: $errorMsg,
                meta: $meta,
            );

            if ($imageFallbackResult !== null) {
                return $imageFallbackResult;
            }

            if ($this->shouldFallbackReengagementTemplate($resolvedType, $errorCode, $errorMsg)) {
                WaLog::warning('[Sender] Text send rejected by 24h rule, retrying with template fallback', array_merge([
                    'to' => WaLog::maskPhone($normalizedE164),
                    'http_status' => $response->status(),
                    'error_code' => $errorCode,
                    'error_msg' => mb_substr($errorMsg, 0, 300),
                    'duration_ms' => $durationMs,
                ], $meta));

                $fallbackResult = $this->sendTemplateReengagement(
                    toPhoneE164: $toPhoneE164,
                    meta: array_merge($meta, ['fallback_from' => $resolvedType]),
                );

                $fallbackResponse = is_array($fallbackResult['response'] ?? null)
                    ? $fallbackResult['response']
                    : [];

                $fallbackResponse['_delivery'] = array_merge(
                    is_array($fallbackResponse['_delivery'] ?? null) ? $fallbackResponse['_delivery'] : [],
                    [
                        'requested_type' => $resolvedType,
                        'sent_type' => $fallbackResult['status'] === 'sent' ? 'template' : ($fallbackResult['sent_type'] ?? 'template'),
                        'reengagement_template_fallback_used' => true,
                        'reengagement_http_status' => $response->status(),
                        'reengagement_error_code' => $errorCode,
                        'reengagement_error' => $errorMsg,
                    ],
                );

                $fallbackResult['response'] = $fallbackResponse;
                $fallbackResult['requested_type'] = $resolvedType;
                $fallbackResult['sent_type'] = $fallbackResult['status'] === 'sent' ? 'template' : ($fallbackResult['sent_type'] ?? 'template');
                $fallbackResult['fallback_used'] = true;

                return $fallbackResult;
            }

            WaLog::warning('[Sender] Send failed', array_merge([
                'to' => WaLog::maskPhone($normalizedE164),
                'http_status' => $response->status(),
                'error_code' => $errorCode,
                'error_msg' => mb_substr($errorMsg, 0, 300),
                'duration_ms' => $durationMs,
            ], $meta));

            return $this->result('failed', $response->json(), $errorMsg);
        } catch (\Throwable $e) {
            WaLog::error('[Sender] Exception during HTTP send', array_merge([
                'to' => WaLog::maskPhone($normalizedE164 !== '' ? $normalizedE164 : $toPhoneE164),
                'error' => $e->getMessage(),
            ], $meta));

            return $this->result('error', null, $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $meta
     * @return array{status:string,provider:string,response:array|null,error:string|null,requested_type?:string,sent_type?:string,fallback_used?:bool}
     */
    public function sendTemplateReengagement(string $toPhoneE164, array $meta = []): array
    {
        $normalizedE164 = $this->phoneService->toE164($toPhoneE164);
        $to = $this->phoneService->toDigits($toPhoneE164);

        if (! $this->isEnabled()) {
            return $this->result('skipped');
        }

        if ($normalizedE164 === '' || ! $this->phoneService->isValidE164($normalizedE164) || $to === '') {
            return $this->result('failed', null, 'Invalid recipient phone number.');
        }

        $templateName = trim((string) config('services.whatsapp.reengagement_template_name', ''));
        $languageCode = trim((string) config('services.whatsapp.reengagement_template_language', 'id'));
        $componentsJson = (string) config('services.whatsapp.reengagement_template_components_json', '');

        if ($templateName === '') {
            return $this->result('failed', null, 'Fallback template belum dikonfigurasi.');
        }

        $components = [];
        if ($componentsJson !== '') {
            $decoded = json_decode($componentsJson, true);
            if (is_array($decoded)) {
                $components = $decoded;
            }
        }

        $endpoint = "{$this->graphBaseUrl}/{$this->phoneNumberId}/messages";
        $requestBody = [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'template',
            'template' => array_filter([
                'name' => $templateName,
                'language' => ['code' => $languageCode !== '' ? $languageCode : 'id'],
                'components' => $components !== [] ? $components : null,
            ], static fn (mixed $value): bool => $value !== null),
        ];

        try {
            $response = Http::withToken($this->accessToken)
                ->timeout($this->timeoutSeconds)
                ->post($endpoint, $requestBody);

            if ($response->successful()) {
                $body = $response->json() ?? [];
                $body['_delivery'] = array_merge(
                    is_array($body['_delivery'] ?? null) ? $body['_delivery'] : [],
                    [
                        'sent_type' => 'template',
                        'template_name' => $templateName,
                        'template_language' => $languageCode,
                    ],
                );

                return $this->result('sent', $body);
            }

            $errorMsg = (string) ($response->json('error.message') ?? $response->body());

            return $this->result('failed', $response->json(), $errorMsg);
        } catch (\Throwable $e) {
            return $this->result('error', null, $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed>|null $response
     * @return array{status:string,provider:string,response:array|null,error:string|null}
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
     * @param array<string, mixed> $providerPayload
     * @param array<string, mixed> $meta
     * @return array<string, mixed>|null
     */
    private function retryAudioWithLinkFallback(
        string $toPhoneE164,
        string $text,
        string $resolvedType,
        array $providerPayload,
        int $responseStatus,
        string $errorMessage,
        array $meta,
    ): ?array {
        if ($resolvedType !== 'audio') {
            return null;
        }

        if (($meta['fallback_from'] ?? null) === 'audio_media_id') {
            return null;
        }

        $primaryAudio = is_array($providerPayload['audio'] ?? null) ? $providerPayload['audio'] : [];
        $fallbackLink = trim((string) ($providerPayload['_audio_link_fallback'] ?? ''));

        if (blank($primaryAudio['id'] ?? null) || $fallbackLink === '') {
            return null;
        }

        WaLog::warning('[Sender] Audio send rejected, retrying with direct link fallback', array_merge([
            'to' => WaLog::maskPhone($toPhoneE164),
            'http_status' => $responseStatus,
            'error_msg' => mb_substr($errorMessage, 0, 300),
        ], $meta));

        $fallbackResult = $this->sendMessage(
            toPhoneE164: $toPhoneE164,
            text: $text,
            messageType: 'audio',
            providerPayload: [
                'audio' => [
                    'link' => $fallbackLink,
                ],
            ],
            meta: array_merge($meta, ['fallback_from' => 'audio_media_id']),
        );

        $fallbackResponse = is_array($fallbackResult['response'] ?? null)
            ? $fallbackResult['response']
            : [];
        $fallbackResponse['_delivery'] = array_merge(
            is_array($fallbackResponse['_delivery'] ?? null) ? $fallbackResponse['_delivery'] : [],
            [
                'requested_type' => 'audio',
                'sent_type' => 'audio',
                'audio_link_fallback_used' => true,
                'audio_primary_transport' => 'id',
                'audio_fallback_transport' => 'link',
                'audio_http_status' => $responseStatus,
                'audio_error' => $errorMessage,
            ],
        );
        $fallbackResult['response'] = $fallbackResponse;
        $fallbackResult['requested_type'] = 'audio';
        $fallbackResult['sent_type'] = 'audio';
        $fallbackResult['fallback_used'] = true;

        return $fallbackResult;
    }

    /**
     * @param array<string, mixed> $providerPayload
     * @param array<string, mixed> $meta
     * @return array<string, mixed>|null
     */
    private function retryImageWithLinkFallback(
        string $toPhoneE164,
        string $text,
        string $resolvedType,
        array $providerPayload,
        int $responseStatus,
        string $errorMessage,
        array $meta,
    ): ?array {
        if ($resolvedType !== 'image') {
            return null;
        }

        if (($meta['fallback_from'] ?? null) === 'image_media_id') {
            return null;
        }

        $primaryImage = is_array($providerPayload['image'] ?? null) ? $providerPayload['image'] : [];
        $fallbackLink = trim((string) ($providerPayload['_image_link_fallback'] ?? ''));

        if (blank($primaryImage['id'] ?? null) || $fallbackLink === '') {
            return null;
        }

        WaLog::warning('[Sender] Image send rejected, retrying with direct link fallback', array_merge([
            'to' => WaLog::maskPhone($toPhoneE164),
            'http_status' => $responseStatus,
            'error_msg' => mb_substr($errorMessage, 0, 300),
        ], $meta));

        $fallbackResult = $this->sendMessage(
            toPhoneE164: $toPhoneE164,
            text: $text,
            messageType: 'image',
            providerPayload: [
                'image' => [
                    'link' => $fallbackLink,
                ],
                'caption' => $providerPayload['caption'] ?? null,
            ],
            meta: array_merge($meta, ['fallback_from' => 'image_media_id']),
        );

        $fallbackResponse = is_array($fallbackResult['response'] ?? null)
            ? $fallbackResult['response']
            : [];
        $fallbackResponse['_delivery'] = array_merge(
            is_array($fallbackResponse['_delivery'] ?? null) ? $fallbackResponse['_delivery'] : [],
            [
                'requested_type' => 'image',
                'sent_type' => 'image',
                'image_link_fallback_used' => true,
                'image_primary_transport' => 'id',
                'image_fallback_transport' => 'link',
                'image_http_status' => $responseStatus,
                'image_error' => $errorMessage,
            ],
        );
        $fallbackResult['response'] = $fallbackResponse;
        $fallbackResult['requested_type'] = 'image';
        $fallbackResult['sent_type'] = 'image';
        $fallbackResult['fallback_used'] = true;

        return $fallbackResult;
    }

    /**
     * @param array<string, mixed> $providerPayload
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

        if ($messageType === 'contacts') {
            return [
                'messaging_product' => 'whatsapp',
                'to' => $to,
                'type' => 'contacts',
                'contacts' => $providerPayload['contacts'] ?? [],
            ];
        }

        if ($messageType === 'audio') {
            $audio = is_array($providerPayload['audio'] ?? null) ? $providerPayload['audio'] : [];

            return [
                'messaging_product' => 'whatsapp',
                'to' => $to,
                'type' => 'audio',
                'audio' => array_filter([
                    'id' => Arr::get($audio, 'id'),
                    'link' => Arr::get($audio, 'link'),
                ], static fn (mixed $value): bool => filled($value)),
            ];
        }

        if ($messageType === 'image') {
            $image = is_array($providerPayload['image'] ?? null) ? $providerPayload['image'] : [];
            $caption = trim((string) ($providerPayload['caption'] ?? $text));
            $imageLink = Arr::get($image, 'link');
            $imageId = $imageLink ? null : Arr::get($image, 'id');

            return [
                'messaging_product' => 'whatsapp',
                'to' => $to,
                'type' => 'image',
                'image' => array_filter([
                    'id' => $imageId,
                    'link' => $imageLink,
                    'caption' => $caption !== '' ? $caption : null,
                ], static fn (mixed $value): bool => filled($value)),
            ];
        }

        if ($messageType === 'document') {
            $document = is_array($providerPayload['document'] ?? null) ? $providerPayload['document'] : [];
            $caption = trim((string) ($providerPayload['caption'] ?? ''));
            $filename = trim((string) ($providerPayload['filename'] ?? ''));
            $documentLink = Arr::get($document, 'link');
            $documentId = $documentLink ? null : Arr::get($document, 'id');

            return [
                'messaging_product' => 'whatsapp',
                'to' => $to,
                'type' => 'document',
                'document' => array_filter([
                    'id' => $documentId,
                    'link' => $documentLink,
                    'caption' => $caption !== '' ? $caption : null,
                    'filename' => $filename !== '' ? $filename : null,
                ], static fn (mixed $value): bool => filled($value)),
            ];
        }

        if ($messageType === 'location') {
            $location = is_array($providerPayload['location'] ?? null) ? $providerPayload['location'] : [];

            return [
                'messaging_product' => 'whatsapp',
                'to' => $to,
                'type' => 'location',
                'location' => array_filter([
                    'latitude' => (float) Arr::get($location, 'latitude', 0),
                    'longitude' => (float) Arr::get($location, 'longitude', 0),
                    'name' => Arr::get($location, 'name'),
                    'address' => Arr::get($location, 'address'),
                ], static fn (mixed $value): bool => $value !== null && $value !== ''),
            ];
        }

        return [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'text',
            'text' => [
                'body' => $text,
                'preview_url' => false,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $providerPayload
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

        if (
            $messageType === 'contacts'
            && isset($providerPayload['contacts'])
            && is_array($providerPayload['contacts'])
            && $providerPayload['contacts'] !== []
        ) {
            return 'contacts';
        }

        if (
            $messageType === 'audio'
            && is_array($providerPayload['audio'] ?? null)
            && (
                filled($providerPayload['audio']['id'] ?? null)
                || filled($providerPayload['audio']['link'] ?? null)
            )
        ) {
            return 'audio';
        }

        if (
            $messageType === 'image'
            && is_array($providerPayload['image'] ?? null)
            && (
                filled($providerPayload['image']['id'] ?? null)
                || filled($providerPayload['image']['link'] ?? null)
            )
        ) {
            return 'image';
        }

        if (
            $messageType === 'document'
            && is_array($providerPayload['document'] ?? null)
            && (
                filled($providerPayload['document']['id'] ?? null)
                || filled($providerPayload['document']['link'] ?? null)
            )
        ) {
            return 'document';
        }

        if (
            $messageType === 'location'
            && is_array($providerPayload['location'] ?? null)
        ) {
            return 'location';
        }

        return 'text';
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function shouldFallbackInteractiveToText(
        string $resolvedType,
        int $httpStatus,
        string $errorMessage,
        array $meta = [],
    ): bool
    {
        if ($resolvedType !== 'interactive') {
            return false;
        }

        if (($meta['disable_interactive_text_fallback'] ?? false) === true) {
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

    private function shouldFallbackReengagementTemplate(string $resolvedType, int $errorCode, string $errorMessage): bool
    {
        if (! in_array($resolvedType, ['text', 'audio', 'image', 'document', 'location', 'contacts', 'interactive'], true)) {
            return false;
        }

        if (! (bool) config('services.whatsapp.reengagement_template_enabled', true)) {
            return false;
        }

        if ($errorCode === 131047) {
            return true;
        }

        $normalized = mb_strtolower(trim($errorMessage), 'UTF-8');

        return str_contains($normalized, 're-engagement')
            || str_contains($normalized, '24 hours have passed');
    }
}