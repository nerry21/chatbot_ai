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
        /** @var User|null $user */
        $user = $request->attributes->get('admin_mobile_user');

        if (! $user instanceof User) {
            return response()->json([
                'success' => false,
                'message' => 'Sesi admin mobile tidak valid.',
            ], 401);
        }

        $messageType = (string) $request->input('message_type', 'text');
        $text = $messageType === 'audio'
            ? (string) ($request->input('caption') ?: '[Voice note admin]')
            : (string) $request->input('message');

        $outboundPayload = $messageType === 'audio'
            ? [
                'audio' => [
                    'link' => (string) $request->input('audio_url'),
                    'voice' => (bool) $request->boolean('voice', true),
                ],
                'mime_type' => $request->input('mime_type'),
                'caption' => $request->input('caption'),
            ]
            : [];

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
                    : ($conversation->channel === 'mobile_live_chat'
                        ? 'Balasan admin berhasil dikirim ke live chat.'
                        : 'Balasan admin berhasil diantrekan ke WhatsApp.')
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
}
