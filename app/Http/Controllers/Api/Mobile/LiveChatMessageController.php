<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Api\Mobile\Concerns\RespondsWithMobileJson;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Mobile\SendLiveChatMessageRequest;
use App\Http\Resources\Mobile\CustomerResource;
use App\Http\Resources\Mobile\ConversationMessageResource;
use App\Http\Resources\Mobile\ConversationResource;
use App\Models\Conversation;
use App\Services\Mobile\MobileAuthService;
use App\Services\Mobile\MobileConversationService;
use App\Services\Mobile\MobileMessageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LiveChatMessageController extends Controller
{
    use RespondsWithMobileJson;

    public function __construct(
        private readonly MobileAuthService $mobileAuthService,
        private readonly MobileConversationService $conversationService,
        private readonly MobileMessageService $messageService,
    ) {}

    public function index(Request $request, Conversation $conversation): JsonResponse
    {
        $customer = $this->mobileAuthService->currentCustomer($request);
        $messages = $this->messageService->list($customer, $conversation);
        $detail = $this->conversationService->detail($customer, $conversation->fresh() ?? $conversation);

        return $this->successResponse('Daftar pesan mobile berhasil diambil.', [
            'customer' => CustomerResource::make($customer),
            'conversation' => ConversationResource::make($detail),
            'messages' => ConversationMessageResource::collection($messages),
            'meta' => $this->meta($detail),
        ]);
    }

    public function store(SendLiveChatMessageRequest $request, Conversation $conversation): JsonResponse
    {
        $customer = $this->mobileAuthService->currentCustomer($request);
        $result = $this->messageService->send($customer, $conversation, $request->validated());
        $detail = $this->conversationService->detail($customer, $conversation->fresh() ?? $conversation);

        return $this->successResponse('Pesan mobile berhasil diproses.', [
            'customer' => CustomerResource::make($customer),
            'conversation' => ConversationResource::make($detail),
            'message' => ConversationMessageResource::make($result['message']),
            'duplicate' => $result['duplicate'],
            'meta' => $this->meta($detail),
        ], $result['duplicate'] ? 200 : 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function meta(Conversation $conversation): array
    {
        return [
            'channel' => $conversation->channel,
            'channel_label' => $conversation->channel_label,
            'source_app' => $conversation->source_app,
            'source_label' => $conversation->source_label,
            'poll_interval_ms' => (int) config('chatbot.mobile_live_chat.poll_interval_ms', 3000),
        ];
    }
}
