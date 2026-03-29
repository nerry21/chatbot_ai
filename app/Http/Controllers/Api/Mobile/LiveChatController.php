<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Api\Mobile\Concerns\RespondsWithMobileJson;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Mobile\MarkReadRequest;
use App\Http\Requests\Api\Mobile\StartConversationRequest;
use App\Http\Resources\Mobile\CustomerResource;
use App\Http\Resources\Mobile\ConversationMessageResource;
use App\Http\Resources\Mobile\ConversationResource;
use App\Models\Conversation;
use App\Services\Mobile\MobileAuthService;
use App\Services\Mobile\MobileConversationService;
use App\Services\Mobile\MobileMessageService;
use App\Services\Mobile\MobilePollingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LiveChatController extends Controller
{
    use RespondsWithMobileJson;

    public function __construct(
        private readonly MobileAuthService $mobileAuthService,
        private readonly MobileConversationService $conversationService,
        private readonly MobileMessageService $messageService,
        private readonly MobilePollingService $pollingService,
    ) {}

    public function start(StartConversationRequest $request): JsonResponse
    {
        $customer = $this->mobileAuthService->currentCustomer($request);
        $conversation = $this->conversationService->start($customer, $request->validated());
        $submittedMessage = null;
        $duplicate = false;

        if (filled($request->validated('opening_message'))) {
            $result = $this->messageService->send($customer, $conversation, [
                'message' => $request->validated('opening_message'),
                'client_message_id' => $request->validated('client_message_id'),
            ]);

            $submittedMessage = $result['message'];
            $duplicate = $result['duplicate'];
        }

        $conversation = $this->conversationService->detail($customer, $conversation->fresh() ?? $conversation);

        return $this->successResponse('Percakapan mobile berhasil disiapkan.', [
            'customer' => CustomerResource::make($customer),
            'conversation' => ConversationResource::make($conversation),
            'submitted_message' => $submittedMessage ? ConversationMessageResource::make($submittedMessage) : null,
            'duplicate' => $duplicate,
            'meta' => $this->meta($conversation, [
                'created' => true,
            ]),
        ], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $customer = $this->mobileAuthService->currentCustomer($request);
        $conversations = $this->conversationService->list($customer);

        return $this->successResponse('Daftar percakapan mobile berhasil diambil.', [
            'customer' => CustomerResource::make($customer),
            'conversations' => ConversationResource::collection($conversations),
            'meta' => $this->meta(),
        ]);
    }

    public function show(Request $request, Conversation $conversation): JsonResponse
    {
        $customer = $this->mobileAuthService->currentCustomer($request);
        $conversation = $this->conversationService->detail($customer, $conversation);

        return $this->successResponse('Detail percakapan mobile berhasil diambil.', [
            'conversation' => ConversationResource::make($conversation),
            'meta' => $this->meta($conversation),
        ]);
    }

    public function poll(Request $request, Conversation $conversation): JsonResponse
    {
        $customer = $this->mobileAuthService->currentCustomer($request);
        $afterMessageId = $request->integer('after_message_id') ?: null;
        $payload = $this->pollingService->poll($customer, $conversation, $afterMessageId);

        return $this->successResponse('Polling percakapan mobile berhasil.', [
            'conversation' => ConversationResource::make($payload['conversation']),
            'messages' => ConversationMessageResource::collection($payload['messages']),
            'meta' => $this->meta($payload['conversation'], [
                'latest_message_id' => $payload['latest_message_id'],
                'unread_count' => $payload['unread_count'],
                'delta_count' => $payload['delta_count'],
                'server_time' => now()->toIso8601String(),
            ]),
        ]);
    }

    public function markRead(MarkReadRequest $request, Conversation $conversation): JsonResponse
    {
        $customer = $this->mobileAuthService->currentCustomer($request);
        $updated = $this->messageService->markRead(
            $customer,
            $conversation,
            $request->validated('last_read_message_id'),
        );
        $detail = $this->conversationService->detail($customer, $conversation->fresh() ?? $conversation);

        return $this->successResponse('Pesan berhasil ditandai dibaca.', [
            'updated_count' => $updated,
            'conversation' => ConversationResource::make($detail),
            'meta' => $this->meta($detail),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function meta(?Conversation $conversation = null, array $extra = []): array
    {
        return array_merge([
            'channel' => $conversation?->channel ?? 'mobile_live_chat',
            'channel_label' => $conversation?->channel_label ?? 'Mobile Live Chat',
            'source_app' => $conversation?->source_app ?? config('chatbot.mobile_live_chat.default_source_app', 'flutter'),
            'source_label' => $conversation?->source_label ?? 'flutter',
            'poll_interval_ms' => (int) config('chatbot.mobile_live_chat.poll_interval_ms', 3000),
        ], $extra);
    }
}
