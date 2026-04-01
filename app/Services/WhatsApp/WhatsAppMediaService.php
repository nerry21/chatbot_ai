<?php

namespace App\Services\WhatsApp;

use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class WhatsAppMediaService
{
    private readonly string $accessToken;
    private readonly string $phoneNumberId;
    private readonly string $graphBaseUrl;
    private readonly int $timeoutSeconds;

    public function __construct()
    {
        $this->accessToken = (string) config('chatbot.whatsapp.access_token', '');
        $this->phoneNumberId = (string) config('chatbot.whatsapp.phone_number_id', '');
        $this->graphBaseUrl = rtrim((string) config('chatbot.whatsapp.graph_base_url', 'https://graph.facebook.com/v19.0'), '/');
        $this->timeoutSeconds = (int) config('chatbot.whatsapp.send_timeout_seconds', 15);
    }

    public function uploadFromContents(string $contents, string $fileName, string $mimeType): string
    {
        if ($this->accessToken === '') {
            throw new RuntimeException('WhatsApp access token is not configured.');
        }

        if ($this->phoneNumberId === '') {
            throw new RuntimeException('WhatsApp phone number id is not configured.');
        }

        if ($contents === '') {
            throw new RuntimeException('WhatsApp media contents are empty.');
        }

        $normalizedMimeType = $this->normalizeMimeType($mimeType);
        $normalizedFileName = $this->normalizeFileName($fileName, $normalizedMimeType);

        $response = Http::withToken($this->accessToken)
            ->acceptJson()
            ->timeout($this->timeoutSeconds)
            ->attach(
                'file',
                $contents,
                $normalizedFileName,
                [
                    'Content-Type' => $normalizedMimeType,
                ],
            )
            ->post("{$this->graphBaseUrl}/{$this->phoneNumberId}/media", [
                'messaging_product' => 'whatsapp',
                'type' => $normalizedMimeType,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException($this->errorMessage($response, 'Failed to upload WhatsApp media content.'));
        }

        $uploadedMediaId = trim((string) $response->json('id', ''));
        if ($uploadedMediaId === '') {
            throw new RuntimeException('WhatsApp uploaded media id is missing.');
        }

        return $uploadedMediaId;
    }

    /**
     * @return array{contents: string, mime_type: string, file_name: string, size_bytes: int}
     */
    public function downloadByMediaId(string $mediaId): array
    {
        $normalizedMediaId = trim($mediaId);
        if ($normalizedMediaId === '') {
            throw new RuntimeException('WhatsApp media id is required.');
        }

        if ($this->accessToken === '') {
            throw new RuntimeException('WhatsApp access token is not configured.');
        }

        $metadataResponse = Http::withToken($this->accessToken)
            ->timeout($this->timeoutSeconds)
            ->get("{$this->graphBaseUrl}/{$normalizedMediaId}");

        if (! $metadataResponse->successful()) {
            throw new RuntimeException($this->errorMessage($metadataResponse, 'Failed to fetch WhatsApp media metadata.'));
        }

        $downloadUrl = trim((string) $metadataResponse->json('url', ''));
        if ($downloadUrl === '') {
            throw new RuntimeException('WhatsApp media download URL is missing.');
        }

        $downloadResponse = Http::withToken($this->accessToken)
            ->timeout($this->timeoutSeconds)
            ->get($downloadUrl);

        if (! $downloadResponse->successful()) {
            throw new RuntimeException($this->errorMessage($downloadResponse, 'Failed to download WhatsApp media content.'));
        }

        $mimeType = trim((string) ($metadataResponse->json('mime_type') ?: $downloadResponse->header('Content-Type') ?: 'application/octet-stream'));
        $contents = $downloadResponse->body();

        return [
            'contents' => $contents,
            'mime_type' => $mimeType !== '' ? $mimeType : 'application/octet-stream',
            'file_name' => $this->buildFileName($normalizedMediaId, $mimeType),
            'size_bytes' => strlen($contents),
        ];
    }

    private function buildFileName(string $mediaId, string $mimeType): string
    {
        $extension = $this->extensionForMimeType($mimeType);

        return $mediaId.'.'.$extension;
    }

    private function normalizeMimeType(string $mimeType): string
    {
        $normalized = strtolower(trim($mimeType));

        return match ($normalized) {
            '', 'image/jpg', 'image/pjpeg' => 'image/jpeg',
            'image/heic', 'image/heif' => 'image/jpeg',
            default => $normalized,
        };
    }

    private function normalizeFileName(string $fileName, string $mimeType): string
    {
        $trimmed = trim($fileName);
        $extension = pathinfo($trimmed, PATHINFO_EXTENSION);

        if ($trimmed === '') {
            return 'upload.'.$this->extensionForMimeType($mimeType);
        }

        if ($extension !== '') {
            return $trimmed;
        }

        return $trimmed.'.'.$this->extensionForMimeType($mimeType);
    }

    private function extensionForMimeType(string $mimeType): string
    {
        return match ($this->normalizeMimeType($mimeType)) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => 'bin',
        };
    }

    private function errorMessage(HttpResponse $response, string $fallback): string
    {
        $providerError = trim((string) ($response->json('error.message') ?? ''));

        if ($providerError !== '') {
            return $providerError;
        }

        $body = trim($response->body());

        return $body !== '' ? $body : $fallback;
    }
}
