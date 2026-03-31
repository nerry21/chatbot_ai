<?php

namespace App\Http\Controllers\Api\AdminMobile;

use App\Http\Controllers\Api\AdminMobile\Concerns\RespondsWithAdminMobileJson;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\AdminMobile\CloseConversationStatusRequest;
use App\Http\Requests\Api\AdminMobile\EscalateConversationStatusRequest;
use App\Http\Requests\Api\AdminMobile\MarkConversationReadRequest;
use App\Http\Requests\Api\AdminMobile\ReleaseConversationRequest;
use App\Http\Requests\Api\AdminMobile\ReopenConversationStatusRequest;
use App\Http\Requests\Api\AdminMobile\SendConversationMessageRequest;
use App\Http\Requests\Api\AdminMobile\StoreConversationNoteRequest;
use App\Http\Requests\Api\AdminMobile\StoreConversationTagRequest;
use App\Http\Requests\Api\AdminMobile\TakeoverConversationRequest;
use App\Http\Resources\AdminMobile\ConversationDetailResource;
use App\Http\Resources\AdminMobile\ConversationMessageResource;
use App\Http\Resources\AdminMobile\InsightPaneResource;
use App\Http\Resources\AdminMobile\ThreadGroupResource;
use App\Models\Conversation;
use App\Models\User;
use App\Services\AdminMobile\AdminMobileAuthService;
use App\Services\Chatbot\AdminConversationMessageService;
use App\Services\Chatbot\AdminConversationWorkspaceService;
use App\Services\Chatbot\BotAutomationToggleService;
use App\Services\Chatbot\ConversationReadService;
use App\Services\Chatbot\ConversationStatusService;
use App\Services\Chatbot\ConversationTagService;
use App\Services\Chatbot\InternalNoteService;
use App\Services\Mobile\MobileConversationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ConversationActionController extends Controller
{
    use RespondsWithAdminMobileJson;

    public function __construct(
        private readonly AdminMobileAuthService $adminMobileAuthService,
        private readonly AdminConversationWorkspaceService $workspaceService,
        private readonly AdminConversationMessageService $messageService,
        private readonly BotAutomationToggleService $botToggleService,
        private readonly ConversationReadService $readService,
        private readonly MobileConversationService $mobileConversationService,
        private readonly ConversationTagService $tagService,
        private readonly InternalNoteService $noteService,
        private readonly ConversationStatusService $statusService,
    ) {}

    public function storeMessage(
        SendConversationMessageRequest $request,
        Conversation $conversation,
    ): JsonResponse {
        $user = $this->adminMobileAuthService->currentUser($request);
        $conversation->loadMissing('customer');
        $messageText = trim((string) $request->validated('message'));

        if ($conversation->customer === null) {
            throw new HttpException(422, 'Customer tidak valid, tidak dapat mengirim pesan.');
        }

        if ($messageText === '') {
            throw new HttpException(422, 'Pesan admin tidak boleh kosong.');
        }

        if ($conversation->isTerminal()) {
            throw new HttpException(422, 'Conversation ditutup. Reopen sebelum mengirim pesan baru.');
        }

        $result = $this->messageService->send(
            conversation: $conversation,
            text: $messageText,
            adminId: $user->id,
            source: 'admin_mobile_api',
        );

        if ($result['status'] === 'failed') {
            throw new HttpException(422, $result['error'] ?? 'Pesan gagal dikirim.');
        }

        $detail = $this->workspaceService->conversationDetailData($conversation->fresh() ?? $conversation, $user->id, false);

        return $this->actionResponse(
            message: $result['duplicate']
                ? 'Pesan yang sama sudah diantrekan. Duplikat diabaikan.'
                : $this->sendSuccessMessage($result['transport'] ?? $conversation->channel),
            action: 'send_message',
            actor: $user,
            detail: $detail,
            actionResult: [
                'transport' => $result['transport'] ?? $conversation->channel,
                'duplicate' => (bool) $result['duplicate'],
                'dispatch_status' => $result['dispatch_status'] ?? null,
                'message' => ConversationMessageResource::make($result['message']),
            ],
            status: $result['duplicate'] ? 200 : 201,
        );
    }

    public function markRead(
        MarkConversationReadRequest $request,
        Conversation $conversation,
    ): JsonResponse {
        $user = $this->adminMobileAuthService->currentUser($request);

        $this->readService->markAsRead($conversation, $user->id);
        $this->mobileConversationService->touchAdminRead($conversation);

        $detail = $this->workspaceService->conversationDetailData($conversation->fresh() ?? $conversation, $user->id, false);

        return $this->actionResponse(
            message: 'Conversation berhasil ditandai dibaca.',
            action: 'mark_read',
            actor: $user,
            detail: $detail,
            actionResult: [
                'unread_count' => (int) ($detail['selectedConversation']?->unread_messages_count ?? 0),
            ],
        );
    }

    public function takeover(
        TakeoverConversationRequest $request,
        Conversation $conversation,
    ): JsonResponse {
        $user = $this->adminMobileAuthService->currentUser($request);
        $updatedConversation = $this->botToggleService->registerAdminTakeover(
            conversation: $conversation,
            adminId: $user->id,
            autoResumeMinutes: $this->botToggleService->autoResumeMinutes(),
            reason: 'admin_mobile_takeover',
        );
        $detail = $this->workspaceService->conversationDetailData($updatedConversation, $user->id, false);

        return $this->actionResponse(
            message: 'Percakapan berhasil diambil alih. Bot sekarang dinonaktifkan.',
            action: 'takeover',
            actor: $user,
            detail: $detail,
            actionResult: [
                'mode' => $detail['selectedConversation']?->currentOperationalMode(),
            ],
        );
    }

    public function release(
        ReleaseConversationRequest $request,
        Conversation $conversation,
    ): JsonResponse {
        $user = $this->adminMobileAuthService->currentUser($request);
        $updatedConversation = $this->botToggleService->turnBotOn(
            conversation: $conversation,
            actorAdminId: $user->id,
            reason: 'admin_mobile_release',
        );
        $detail = $this->workspaceService->conversationDetailData($updatedConversation, $user->id, false);

        return $this->actionResponse(
            message: 'Percakapan berhasil dikembalikan ke bot.',
            action: 'release',
            actor: $user,
            detail: $detail,
            actionResult: [
                'mode' => $detail['selectedConversation']?->currentOperationalMode(),
            ],
        );
    }

    public function storeTag(
        StoreConversationTagRequest $request,
        Conversation $conversation,
    ): JsonResponse {
        $user = $this->adminMobileAuthService->currentUser($request);
        $validated = $request->validated();

        if ($validated['target'] === 'customer') {
            if ($conversation->customer === null) {
                throw new HttpException(422, 'Customer pada conversation ini tidak ditemukan.');
            }

            $tag = $this->tagService->addCustomerTag(
                customer: $conversation->customer,
                tag: (string) $validated['tag'],
                actorId: $user->id,
                conversation: $conversation,
            );
        } else {
            $tag = $this->tagService->addConversationTag(
                conversation: $conversation,
                tag: (string) $validated['tag'],
                actorId: $user->id,
            );
        }

        $detail = $this->workspaceService->conversationDetailData($conversation->fresh() ?? $conversation, $user->id, false);

        return $this->actionResponse(
            message: $validated['target'] === 'customer'
                ? 'Tag customer berhasil ditambahkan.'
                : 'Tag conversation berhasil ditambahkan.',
            action: 'store_tag',
            actor: $user,
            detail: $detail,
            actionResult: [
                'target' => $validated['target'],
                'tag' => [
                    'id' => $tag->id,
                    'value' => $tag->tag,
                ],
            ],
        );
    }

    public function storeNote(
        StoreConversationNoteRequest $request,
        Conversation $conversation,
    ): JsonResponse {
        $user = $this->adminMobileAuthService->currentUser($request);
        $validated = $request->validated();

        if ($validated['target'] === 'customer') {
            if ($conversation->customer === null) {
                throw new HttpException(422, 'Customer pada conversation ini tidak ditemukan.');
            }

            $note = $this->noteService->addCustomerNote(
                customer: $conversation->customer,
                body: (string) $validated['body'],
                authorId: $user->id,
                conversation: $conversation,
            );
        } else {
            $note = $this->noteService->addConversationNote(
                conversation: $conversation,
                body: (string) $validated['body'],
                authorId: $user->id,
            );
        }

        $detail = $this->workspaceService->conversationDetailData($conversation->fresh() ?? $conversation, $user->id, false);

        return $this->actionResponse(
            message: $validated['target'] === 'customer'
                ? 'Catatan internal customer berhasil ditambahkan.'
                : 'Catatan internal conversation berhasil ditambahkan.',
            action: 'store_note',
            actor: $user,
            detail: $detail,
            actionResult: [
                'target' => $validated['target'],
                'note' => [
                    'id' => $note->id,
                    'body' => $note->body,
                ],
            ],
            status: 201,
        );
    }

    public function escalate(
        EscalateConversationStatusRequest $request,
        Conversation $conversation,
    ): JsonResponse {
        $user = $this->adminMobileAuthService->currentUser($request);
        $updatedConversation = $this->statusService->markEscalated(
            conversation: $conversation,
            actorId: $user->id,
            reason: $request->validated('reason'),
        );
        $detail = $this->workspaceService->conversationDetailData($updatedConversation, $user->id, false);

        return $this->actionResponse(
            message: 'Conversation berhasil ditandai escalated.',
            action: 'status_escalate',
            actor: $user,
            detail: $detail,
            actionResult: [
                'status' => is_string($updatedConversation->status) ? $updatedConversation->status : $updatedConversation->status?->value,
            ],
        );
    }

    public function close(
        CloseConversationStatusRequest $request,
        Conversation $conversation,
    ): JsonResponse {
        $user = $this->adminMobileAuthService->currentUser($request);
        $updatedConversation = $this->statusService->close(
            conversation: $conversation,
            actorId: $user->id,
            reason: $request->validated('reason'),
        );
        $detail = $this->workspaceService->conversationDetailData($updatedConversation, $user->id, false);

        return $this->actionResponse(
            message: 'Conversation berhasil ditutup.',
            action: 'status_close',
            actor: $user,
            detail: $detail,
            actionResult: [
                'status' => is_string($updatedConversation->status) ? $updatedConversation->status : $updatedConversation->status?->value,
            ],
        );
    }

    public function reopen(
        ReopenConversationStatusRequest $request,
        Conversation $conversation,
    ): JsonResponse {
        $user = $this->adminMobileAuthService->currentUser($request);
        $updatedConversation = $this->statusService->reopen(
            conversation: $conversation,
            actorId: $user->id,
        );
        $detail = $this->workspaceService->conversationDetailData($updatedConversation, $user->id, false);

        return $this->actionResponse(
            message: 'Conversation berhasil dibuka kembali.',
            action: 'status_reopen',
            actor: $user,
            detail: $detail,
            actionResult: [
                'status' => is_string($updatedConversation->status) ? $updatedConversation->status : $updatedConversation->status?->value,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $detail
     * @param  array<string, mixed>  $actionResult
     */
    private function actionResponse(
        string $message,
        string $action,
        User $actor,
        array $detail,
        array $actionResult = [],
        int $status = 200,
    ): JsonResponse {
        return $this->successResponse($message, [
            'action' => $action,
            'action_result' => $actionResult,
            'updated_conversation' => new ConversationDetailResource($detail['selectedConversation']),
            'composer_state' => $this->composerState($detail['selectedConversation'], $actor),
            'refreshed_thread_snippet' => $this->threadSnippetPayload($detail['messages']),
            'insight_pane' => new InsightPaneResource($detail['insightPane']),
        ], $status);
    }

    /**
     * @return array<string, mixed>
     */
    private function composerState(Conversation $conversation, User $actor): array
    {
        return [
            'can_send' => ! $conversation->isTerminal(),
            'channel' => $conversation->channel,
            'is_takeover_active' => $conversation->isAdminTakeover(),
            'is_assigned_to_me' => (int) ($conversation->assigned_admin_id ?? 0) === $actor->id,
            'assigned_admin_id' => $conversation->assigned_admin_id,
            'assigned_admin_name' => $conversation->assignedAdmin?->name,
            'needs_human' => (bool) $conversation->needs_human,
            'bot_paused' => (bool) $conversation->bot_paused,
            'operational_mode' => $conversation->currentOperationalMode(),
            'message_hint' => $conversation->isTerminal()
                ? 'Conversation ditutup. Reopen sebelum mengirim pesan baru.'
                : null,
        ];
    }

    /**
     * @param  Collection<int, \App\Models\ConversationMessage>  $messages
     * @return array<string, mixed>
     */
    private function threadSnippetPayload(Collection $messages): array
    {
        $snippetMessages = $messages->take(12)->values();
        $threadGroups = $snippetMessages
            ->groupBy(fn ($message): string => $message->sent_at?->format('d M Y') ?? 'Tanpa tanggal')
            ->map(fn (Collection $group, string $dateLabel): array => [
                'date_label' => $dateLabel,
                'messages' => $group->values(),
            ])
            ->values();

        return [
            'messages' => ConversationMessageResource::collection($snippetMessages),
            'thread_groups' => ThreadGroupResource::collection($threadGroups),
        ];
    }

    private function sendSuccessMessage(string $transport): string
    {
        return $transport === 'mobile_live_chat'
            ? 'Pesan admin berhasil dikirim ke live chat pelanggan.'
            : 'Pesan admin berhasil diantrekan ke customer.';
    }
}
