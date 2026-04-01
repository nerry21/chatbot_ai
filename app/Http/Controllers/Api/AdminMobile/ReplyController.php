<?php

namespace App\Http\Controllers\Api\AdminMobile;

use App\Http\Controllers\Api\AdminMobile\Concerns\RespondsWithAdminMobileJson;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SendConversationReplyRequest;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Chatbot\AdminConversationMessageService;
use App\Services\Chatbot\ConversationReadService;
use Illuminate\Http\UploadedFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

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

        $text = $messageType === 'audio'
            ? (string) (($validated['caption'] ?? '') ?: '[Voice note admin]')
            : ($messageType === 'image'
                ? (string) (($validated['caption'] ?? '') ?: '[Gambar admin]')
                : (string) ($validated['message'] ?? ''));

        $outboundPayload = $messageType === 'audio'
            ? [
                'audio' => [
                    'link' => (string) ($validated['audio_url'] ?? ''),
                    'voice' => (bool) ($validated['voice'] ?? true),
                ],
                'mime_type' => $validated['mime_type'] ?? null,
                'caption' => $validated['caption'] ?? null,
            ]
            : ($messageType === 'image'
                ? $this->storeImagePayload($request->file('image_file'), (string) ($validated['caption'] ?? ''))
                : []);

        $result = $this->messageService->send(
            conversation: $conversation,
            text: $text,
            adminId: (int) $user->id,
            source: $messageType === 'audio' ? 'admin_mobile_voice_note' : 'admin_mobile_omnichannel',
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
            : (
                $messageType === 'audio'
                    ? 'Voice note admin berhasil diantrekan ke WhatsApp.'
                    : ($messageType === 'image'
                        ? 'Gambar admin berhasil diantrekan ke WhatsApp.'
                    : ($conversation->channel === 'mobile_live_chat'
                        ? 'Balasan admin berhasil dikirim ke live chat.'
                        : 'Balasan admin berhasil diantrekan ke WhatsApp.'))
            );

        return $this->successResponse($notice, [
            'notice' => $notice,
            'conversation_id' => (int) $conversation->id,
            'message_id' => (int) (($result['message']->id ?? 0)),
            'transport' => (string) ($result['transport'] ?? $conversation->channel),
            'duplicate' => $duplicate,
            'delivery_status' => (string) ($result['dispatch_status'] ?? ''),
        ], $duplicate ? 200 : 201);
    }

    /**
     * @return array<string, mixed>
     */
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
}
