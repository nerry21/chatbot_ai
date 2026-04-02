<?php

namespace App\Http\Controllers\Api\AdminMobile;

use App\Http\Controllers\Api\AdminMobile\Concerns\RespondsWithAdminMobileJson;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SendConversationReplyRequest;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Chatbot\AdminConversationMessageService;
use App\Services\Chatbot\ConversationReadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

class ReplyController extends Controller
{
    use RespondsWithAdminMobileJson;

    public function __construct(
        private readonly AdminConversationMessageService $messageService,
        private readonly ConversationReadService $readService,
    ) {}

    public function store(
        SendConversationReplyRequest $request,
        Conversation $conversation,
    ): JsonResponse {
        $validated = $request->validated();

        /** @var User|null $user */
        $user = $request->attributes->get('admin_mobile_user');

        if (! $user instanceof User) {
            return response()->json([
                'success' => false,
                'message' => 'Sesi admin mobile tidak valid.',
            ], 401);
        }

        $messageType = (string) ($validated['message_type'] ?? 'text');

        if ($messageType === 'image' && ! $conversation->isWhatsApp()) {
            return response()->json([
                'success' => false,
                'message' => 'Galeri saat ini hanya didukung untuk conversation WhatsApp.',
            ], 422);
        }

        if ($messageType === 'audio' && ! $conversation->isWhatsApp()) {
            return response()->json([
                'success' => false,
                'message' => 'Voice note saat ini hanya didukung untuk conversation WhatsApp.',
            ], 422);
        }

        $text = match ($messageType) {
            'audio' => (string) (($validated['caption'] ?? '') ?: '[Voice note admin]'),
            'image' => (string) (($validated['caption'] ?? '') ?: '[Gambar dari Admin Jet]'),
            default => (string) ($validated['message'] ?? ''),
        };

        $outboundPayload = match ($messageType) {
            'audio' => $this->storeAudioPayload(
                $request->file('audio_file'),
                (string) ($validated['audio_url'] ?? ''),
                (string) ($validated['caption'] ?? ''),
                (bool) ($validated['voice'] ?? true),
                $validated['mime_type'] ?? null,
            ),
            'image' => $this->storeImagePayload(
                $request->file('image_file'),
                (string) ($validated['caption'] ?? ''),
            ),
            default => [],
        };

        $result = $this->messageService->send(
            conversation: $conversation,
            text: $text,
            adminId: (int) $user->id,
            source: $messageType === 'audio'
                ? 'admin_mobile_voice_note'
                : 'admin_mobile_omnichannel',
            messageType: $messageType,
            outboundPayload: $outboundPayload,
        );

        $this->readService->markAsRead($conversation, (int) $user->id);

        if (($result['status'] ?? 'failed') === 'failed') {
            return response()->json([
                'success' => false,
                'message' => (string) ($result['error'] ?? 'Pesan admin gagal dikirim.'),
                'data' => [
                    'conversation_id' => (int) $conversation->id,
                    'transport' => (string) ($result['transport'] ?? $conversation->channel),
                    'duplicate' => false,
                ],
            ], 422);
        }

        $duplicate = (bool) ($result['duplicate'] ?? false);

        $notice = $duplicate
            ? 'Pesan yang sama baru saja dikirim. Duplikat diabaikan.'
            : match ($messageType) {
                'audio' => 'Voice note admin berhasil diantrekan ke WhatsApp.',
                'image' => 'Gambar dari Admin Jet berhasil diantrekan ke WhatsApp.',
                default => $conversation->channel === 'mobile_live_chat'
                    ? 'Balasan admin berhasil dikirim ke live chat.'
                    : 'Balasan admin berhasil diantrekan ke WhatsApp.',
            };

        return $this->successResponse($notice, [
            'notice' => $notice,
            'conversation_id' => (int) $conversation->id,
            'message_id' => (int) (($result['message']->id ?? 0)),
            'transport' => (string) ($result['transport'] ?? $conversation->channel),
            'duplicate' => $duplicate,
            'delivery_status' => (string) ($result['dispatch_status'] ?? ''),
        ], $duplicate ? 200 : 201);
    }

    private function storeImagePayload(?UploadedFile $imageFile, string $caption): array
    {
        if (! $imageFile instanceof UploadedFile) {
            return [];
        }

        $storedPath = $imageFile->store('conversation-media/images', 'public');

        return [
            'image' => [],
            'caption' => $caption !== '' ? $caption : null,
            'mime_type' => $imageFile->getMimeType() ?: $imageFile->getClientMimeType(),
            'original_name' => $imageFile->getClientOriginalName(),
            'size_bytes' => $imageFile->getSize(),
            'storage_disk' => 'public',
            'storage_path' => $storedPath,
        ];
    }

    private function storeAudioPayload(
        ?UploadedFile $audioFile,
        string $audioUrl,
        string $caption,
        bool $voice,
        ?string $mimeType,
    ): array {
        if ($audioFile instanceof UploadedFile) {
            $normalizedMimeType = $this->normalizeAudioMimeType($audioFile, $mimeType);
            $extension = $this->audioExtensionForMimeType($normalizedMimeType, $audioFile);
            $baseName = trim(pathinfo($audioFile->getClientOriginalName(), PATHINFO_FILENAME));
            $safeBaseName = $baseName !== '' ? Str::slug($baseName) : 'voice_note_'.now()->timestamp;
            $storedFileName = $safeBaseName.'.'.$extension;
            $storedPath = $audioFile->storeAs('conversation-media/audio', $storedFileName, 'public');

            return [
                'audio' => [],
                'caption' => $caption !== '' ? $caption : null,
                'voice' => $voice,
                'mime_type' => $normalizedMimeType,
                'original_name' => $this->normalizeOriginalAudioName($audioFile->getClientOriginalName(), $extension),
                'size_bytes' => $audioFile->getSize(),
                'storage_disk' => 'public',
                'storage_path' => $storedPath,
            ];
        }

        return [
            'audio' => [
                'link' => $audioUrl,
                'voice' => $voice,
            ],
            'caption' => $caption !== '' ? $caption : null,
            'mime_type' => $mimeType,
        ];
    }

    private function normalizeAudioMimeType(UploadedFile $audioFile, ?string $mimeType): string
    {
        $originalExtension = strtolower(trim((string) pathinfo($audioFile->getClientOriginalName(), PATHINFO_EXTENSION)));
        if (in_array($originalExtension, ['ogg', 'opus'], true)) {
            return 'audio/ogg';
        }

        if (in_array($originalExtension, ['m4a', 'mp4', 'aac'], true)) {
            return 'audio/mp4';
        }

        if ($originalExtension === 'mp3') {
            return 'audio/mpeg';
        }

        $candidate = strtolower(trim((string) ($mimeType ?: $audioFile->getClientMimeType() ?: $audioFile->getMimeType() ?: 'audio/ogg')));

        return match ($candidate) {
            'audio/opus', 'application/ogg' => 'audio/ogg',
            'video/mp4', 'audio/x-m4a', 'audio/aac' => 'audio/mp4',
            'audio/mp3' => 'audio/mpeg',
            default => $candidate,
        };
    }

    private function audioExtensionForMimeType(string $mimeType, UploadedFile $audioFile): string
    {
        $originalExtension = strtolower(trim((string) pathinfo($audioFile->getClientOriginalName(), PATHINFO_EXTENSION)));
        if ($originalExtension !== '') {
            return match ($originalExtension) {
                'opus' => 'ogg',
                default => $originalExtension,
            };
        }

        return match ($mimeType) {
            'audio/mpeg' => 'mp3',
            'audio/mp4' => 'm4a',
            default => 'ogg',
        };
    }

    private function normalizeOriginalAudioName(string $originalName, string $extension): string
    {
        $baseName = trim(pathinfo($originalName, PATHINFO_FILENAME));
        $safeBaseName = $baseName !== '' ? $baseName : 'voice_note';

        return $safeBaseName.'.'.$extension;
    }
}
