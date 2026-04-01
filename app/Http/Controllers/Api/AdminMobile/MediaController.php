<?php

namespace App\Http\Controllers\Api\AdminMobile;

use App\Http\Controllers\Controller;
use App\Models\ConversationMessage;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class MediaController extends Controller
{
    public function show(ConversationMessage $message): Response
    {
        $rawPayload = is_array($message->raw_payload) ? $message->raw_payload : [];
        $disk = (string) data_get($rawPayload, 'media_storage_disk', '');
        $path = (string) data_get($rawPayload, 'media_storage_path', '');

        abort_if($disk === '' || $path === '', 404);
        abort_unless(Storage::disk($disk)->exists($path), 404);

        $mimeType = (string) (data_get($rawPayload, 'mime_type') ?: Storage::disk($disk)->mimeType($path) ?: 'application/octet-stream');
        $fileName = trim((string) data_get($rawPayload, 'media_original_name', ''));
        $downloadName = $fileName !== '' ? $fileName : basename($path);

        return Storage::disk($disk)->response($path, $downloadName, [
            'Content-Type' => $mimeType,
            'Cache-Control' => 'public, max-age=604800',
            'Content-Disposition' => 'inline; filename="'.addslashes($downloadName).'"',
        ]);
    }
}
