<?php

namespace App\Http\Controllers\Api\AdminMobile;

use App\Http\Controllers\Api\AdminMobile\Concerns\RespondsWithAdminMobileJson;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Chatbot\BotAutomationToggleService;
use App\Services\Chatbot\ConversationReadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BotControlController extends Controller
{
    use RespondsWithAdminMobileJson;

    public function __construct(
        private readonly BotAutomationToggleService $botToggleService,
        private readonly ConversationReadService $readService,
    ) {}

    public function status(Request $request, Conversation $conversation): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->attributes->get('admin_mobile_user');
        $userId = (int) ($user?->id ?? 0);

        $conversation = $this->botToggleService->resumeIfDue($conversation, $userId ?: null);
        $state = $this->botToggleService->statePayload($conversation);

        return $this->successResponse('Status bot conversation berhasil diambil.', [
            'conversation_id' => (int) $conversation->id,
            'bot_enabled' => (bool) ($state['bot_enabled'] ?? false),
            'bot_paused' => (bool) ($state['bot_paused'] ?? false),
            'human_takeover' => (string) ($state['handoff_mode'] ?? 'bot') === 'admin',
            'bot_auto_resume_enabled' => (bool) ($state['bot_auto_resume_enabled'] ?? false),
            'bot_auto_resume_at' => $state['bot_auto_resume_at'] ?? null,
            'last_admin_reply_at' => $state['bot_last_admin_reply_at'] ?? null,
            'unread_count' => $this->readService->unreadCountForConversation($conversation, $userId),
            'bot' => $state,
        ]);
    }

    public function turnOn(Request $request, Conversation $conversation): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->attributes->get('admin_mobile_user');

        if (! $user instanceof User) {
            return response()->json([
                'success' => false,
                'message' => 'Sesi admin mobile tidak valid.',
            ], 401);
        }

        $updated = $this->botToggleService->turnBotOn($conversation, (int) $user->id, 'admin_mobile_toggle_on');
        $state = $this->botToggleService->statePayload($updated);

        return $this->successResponse('Bot berhasil diaktifkan untuk conversation ini.', [
            'conversation_id' => (int) $updated->id,
            'bot_enabled' => true,
            'bot_paused' => false,
            'human_takeover' => false,
            'bot_auto_resume_enabled' => false,
            'bot_auto_resume_at' => null,
            'bot' => $state,
        ]);
    }

    public function turnOff(Request $request, Conversation $conversation): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->attributes->get('admin_mobile_user');

        if (! $user instanceof User) {
            return response()->json([
                'success' => false,
                'message' => 'Sesi admin mobile tidak valid.',
            ], 401);
        }

        $minutes = max(1, min(120, (int) $request->integer('auto_resume_minutes', $this->botToggleService->autoResumeMinutes())));
        $updated = $this->botToggleService->turnBotOff($conversation, (int) $user->id, $minutes, 'admin_mobile_toggle_off');
        $state = $this->botToggleService->statePayload($updated);

        return $this->successResponse('Bot berhasil dinonaktifkan sementara. Admin mengambil alih conversation.', [
            'conversation_id' => (int) $updated->id,
            'bot_enabled' => false,
            'bot_paused' => true,
            'human_takeover' => true,
            'bot_auto_resume_enabled' => true,
            'bot_auto_resume_at' => $state['bot_auto_resume_at'] ?? null,
            'last_admin_reply_at' => $state['bot_last_admin_reply_at'] ?? null,
            'bot' => $state,
        ]);
    }

    public function store(Request $request, Conversation $conversation): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->attributes->get('admin_mobile_user');

        if (! $user instanceof User) {
            return response()->json([
                'success' => false,
                'message' => 'Sesi admin mobile tidak valid.',
            ], 401);
        }

        $payload = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        $conversation = $this->botToggleService->setBotEnabled(
            conversation: $conversation,
            enabled: (bool) $payload['enabled'],
            adminId: (int) $user->id,
            reason: (bool) $payload['enabled'] ? 'manual_bot_on' : 'manual_bot_off',
        );

        $state = $this->botToggleService->statePayload($conversation);

        return $this->successResponse(
            (bool) $state['bot_enabled']
                ? 'Bot aktif kembali untuk percakapan ini.'
                : 'Bot dimatikan. Admin mengambil alih percakapan ini.',
            [
                'conversation_id' => (int) $conversation->id,
                'bot' => $state,
            ],
        );
    }
}
