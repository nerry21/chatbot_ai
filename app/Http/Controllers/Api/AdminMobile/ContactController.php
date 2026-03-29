<?php

namespace App\Http\Controllers\Api\AdminMobile;

use App\Http\Controllers\Api\AdminMobile\Concerns\RespondsWithAdminMobileJson;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SendConversationContactRequest;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Chatbot\AdminConversationContactService;
use App\Services\Chatbot\ConversationReadService;
use Illuminate\Http\JsonResponse;

class ContactController extends Controller
{
    use RespondsWithAdminMobileJson;

    public function __construct(
        private readonly AdminConversationContactService $contactService,
        private readonly ConversationReadService $readService,
    ) {}

    public function store(
        SendConversationContactRequest $request,
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

        $result = $this->contactService->send(
            conversation: $conversation,
            contact: [
                'full_name' => (string) $request->input('full_name'),
                'phone' => (string) $request->input('phone'),
                'email' => $request->input('email'),
                'company' => $request->input('company'),
            ],
            adminId: (int) $user->id,
            source: 'admin_mobile_send_contact',
        );

        $this->readService->markAsRead($conversation, (int) $user->id);

        if (($result['status'] ?? 'failed') === 'failed') {
            return response()->json([
                'success' => false,
                'message' => (string) ($result['error'] ?? 'Kontak gagal dikirim.'),
                'data' => [
                    'conversation_id' => (int) $conversation->id,
                    'transport' => (string) ($result['transport'] ?? $conversation->channel),
                    'duplicate' => false,
                ],
            ], 422);
        }

        $duplicate = (bool) ($result['duplicate'] ?? false);
        $notice = $duplicate
            ? 'Kontak yang sama baru saja dikirim. Duplikat diabaikan.'
            : 'Kontak berhasil diproses untuk dikirim ke customer.';

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
