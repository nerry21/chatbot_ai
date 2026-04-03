<?php

namespace App\Http\Controllers\Api\AdminMobile;

use App\Http\Controllers\Controller;
use App\Models\ConversationMessage;
use App\Services\WhatsApp\WhatsAppMediaService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class MediaController extends Controller
{
    public function show(ConversationMessage $message, WhatsAppMediaService $mediaService): Response
    {
        $rawPayload = is_array($message->raw_payload) ? $message->raw_payload : [];
        $storedMedia = $this->resolveStoredMedia($message, $rawPayload, $mediaService);

        abort_if($storedMedia === null, 404);

        $mimeType = (string) ($storedMedia['mime_type'] ?: Storage::disk($storedMedia['disk'])->mimeType($storedMedia['path']) ?: 'application/octet-stream');
        $downloadName = $storedMedia['download_name'];

        return Storage::disk($storedMedia['disk'])->response($storedMedia['path'], $downloadName, [
            'Content-Type' => $mimeType,
            'Cache-Control' => 'public, max-age=604800',
            'Content-Disposition' => 'inline; filename="'.addslashes($downloadName).'"',
        ]);
    }

    /**
     * @param  array<string, mixed>  $rawPayload
     * @return array{disk: string, path: string, mime_type: string, download_name: string}|null
     */
    private function resolveStoredMedia(
        ConversationMessage $message,
        array $rawPayload,
        WhatsAppMediaService $mediaService,
    ): ?array {
        $disk = trim((string) data_get($rawPayload, 'media_storage_disk', ''));
        $path = trim((string) data_get($rawPayload, 'media_storage_path', ''));

        if ($disk !== '' && $path !== '' && Storage::disk($disk)->exists($path)) {
            return [
                'disk' => $disk,
                'path' => $path,
                'mime_type' => trim((string) data_get($rawPayload, 'mime_type', '')),
                'download_name' => $this->downloadName($rawPayload, $path),
            ];
        }

        $mediaId = trim((string) (
            data_get($rawPayload, 'image.id')
            ?: data_get($rawPayload, 'outbound_payload.image.id')
            ?: data_get($rawPayload, 'audio.id')
            ?: data_get($rawPayload, 'outbound_payload.audio.id')
            ?: data_get($rawPayload, 'video.id')
            ?: data_get($rawPayload, 'outbound_payload.video.id')
            ?: data_get($rawPayload, 'document.id')
            ?: data_get($rawPayload, 'outbound_payload.document.id')
            ?: ''
        ));

        if (! in_array($message->message_type, ['image', 'audio', 'video', 'document'], true) || $mediaId === '') {
            return null;
        }

        $download = $mediaService->downloadByMediaId($mediaId);
        $storedPath = $this->storeRemoteMedia($message, $rawPayload, $download);

        return [
            'disk' => 'public',
            'path' => $storedPath,
            'mime_type' => trim((string) ($download['mime_type'] ?? data_get($rawPayload, 'mime_type', ''))),
            'download_name' => $this->downloadName(
                array_merge($rawPayload, ['media_original_name' => $download['file_name']]),
                $storedPath,
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $rawPayload
     * @param  array{contents: string, mime_type: string, file_name: string, size_bytes: int}  $download
     */
    private function storeRemoteMedia(
        ConversationMessage $message,
        array $rawPayload,
        array $download,
    ): string {
        $safeFileName = Str::slug(pathinfo($download['file_name'], PATHINFO_FILENAME));
        $extension = pathinfo($download['file_name'], PATHINFO_EXTENSION);
        $extension = $extension !== '' ? $extension : 'bin';

        $storedFileName = trim($safeFileName) !== ''
            ? $safeFileName.'.'.$extension
            : $message->id.'.'.$extension;

        $folder = match ($message->message_type) {
            'audio' => 'conversation-media/audio/inbound/',
            'image' => 'conversation-media/images/inbound/',
            'video' => 'conversation-media/videos/inbound/',
            'document' => 'conversation-media/documents/inbound/',
            default => 'conversation-media/misc/inbound/',
        };

        $storedPath = $folder.$message->id.'-'.$storedFileName;

        Storage::disk('public')->put($storedPath, $download['contents']);

        $message->forceFill([
            'raw_payload' => array_merge($rawPayload, [
                'media_storage_disk' => 'public',
                'media_storage_path' => $storedPath,
                'mime_type' => (string) ($download['mime_type'] ?? data_get($rawPayload, 'mime_type')),
                'media_original_name' => (string) ($download['file_name'] ?? basename($storedPath)),
                'media_size_bytes' => (int) ($download['size_bytes'] ?? 0),
            ]),
        ])->save();

        return $storedPath;
    }

    /**
     * @param  array<string, mixed>  $rawPayload
     */
    private function downloadName(array $rawPayload, string $path): string
    {
        $fileName = trim((string) data_get($rawPayload, 'media_original_name', ''));

        return $fileName !== '' ? $fileName : basename($path);
    }
}
