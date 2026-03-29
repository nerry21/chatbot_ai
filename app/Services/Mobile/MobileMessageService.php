<?php

namespace App\Services\Mobile;

use App\Enums\MessageDirection;
use App\Enums\SenderType;
use App\Jobs\ProcessIncomingConversationMessage;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Customer;
use App\Services\Chatbot\ConversationManagerService;
use App\Support\WaLog;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class MobileMessageService
{
    public function __construct(
        private readonly ConversationManagerService $conversationManager,
        private readonly MobileConversationService $conversationService,
    ) {}

    /**
     * @return Collection<int, ConversationMessage>
     */
    public function list(Customer $customer, Conversation $conversation, ?int $afterMessageId = null): Collection
    {
        $conversation = $this->conversationService->detail($customer, $conversation);

        $query = $conversation->messages()
            ->where('sender_type', '!=', SenderType::System->value);

        if ($afterMessageId !== null) {
            $messages = $query
                ->where('id', '>', $afterMessageId)
                ->orderBy('id')
                ->limit((int) config('chatbot.mobile_live_chat.max_messages_per_fetch', 120))
                ->get();
        } else {
            $messages = $query
                ->orderByDesc('id')
                ->limit((int) config('chatbot.mobile_live_chat.max_messages_per_fetch', 120))
                ->get()
                ->sortBy('id')
                ->values();
        }

        $this->markDeliveredToApp($messages);

        return $messages;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{message: ConversationMessage, duplicate: bool}
     */
    public function send(Customer $customer, Conversation $conversation, array $payload): array
    {
        $clientMessageId = $this->nullableString($payload['client_message_id'] ?? null);
        $result = DB::transaction(function () use ($customer, $conversation, $payload, $clientMessageId): array {
            $ownedConversation = $this->conversationService->ensureOwnedConversation($customer, $conversation);

            if ($clientMessageId !== null) {
                $duplicate = $ownedConversation->messages()
                    ->where('direction', MessageDirection::Inbound->value)
                    ->where('sender_type', SenderType::Customer->value)
                    ->where('client_message_id', $clientMessageId)
                    ->latest('id')
                    ->first();

                if ($duplicate !== null) {
                    return [
                        'message' => $duplicate,
                        'duplicate' => true,
                    ];
                }
            }

            $message = $this->conversationManager->appendInboundMessage($ownedConversation, [
                'message_type' => 'text',
                'message_text' => trim((string) $payload['message']),
                'raw_payload' => [
                    'source' => 'mobile_live_chat_api',
                    'transport' => 'http_polling',
                    'customer_id' => $customer->id,
                    'source_app' => $ownedConversation->source_app ?: config('chatbot.mobile_live_chat.default_source_app', 'flutter'),
                ],
                'client_message_id' => $clientMessageId,
                'channel_message_id' => $clientMessageId,
                'sent_at' => now(),
            ]);

            return [
                'message' => $message->fresh() ?? $message,
                'duplicate' => false,
            ];
        });

        if (! $result['duplicate']) {
            ProcessIncomingConversationMessage::dispatch(
                $result['message']->id,
                $result['message']->conversation_id,
                WaLog::traceId(),
            );
        }

        return $result;
    }

    public function markRead(Customer $customer, Conversation $conversation, ?int $lastReadMessageId = null): int
    {
        return DB::transaction(function () use ($customer, $conversation, $lastReadMessageId): int {
            $ownedConversation = $this->conversationService->ensureOwnedConversation($customer, $conversation);
            $resolvedLastReadId = $this->resolveLastReadMessageId($ownedConversation, $lastReadMessageId);

            if ($resolvedLastReadId === null) {
                $this->conversationService->touchCustomerRead($ownedConversation);

                return 0;
            }

            $updated = $ownedConversation->messages()
                ->where('direction', MessageDirection::Outbound->value)
                ->where('sender_type', '!=', SenderType::System->value)
                ->where('id', '<=', $resolvedLastReadId)
                ->whereNull('read_at')
                ->update(['read_at' => now()]);

            $ownedConversation->forceFill(['last_read_at_customer' => now()])->save();

            return (int) $updated;
        });
    }

    /**
     * @param  Collection<int, ConversationMessage>  $messages
     */
    public function markDeliveredToApp(Collection $messages): void
    {
        $timestamp = now();

        $outboundMessageIds = $messages
            ->filter(function (ConversationMessage $message): bool {
                return (is_string($message->direction) ? $message->direction : $message->direction?->value) === MessageDirection::Outbound->value
                    && (is_string($message->sender_type) ? $message->sender_type : $message->sender_type?->value) !== SenderType::System->value
                    && $message->delivered_to_app_at === null;
            })
            ->pluck('id')
            ->all();

        if ($outboundMessageIds === []) {
            return;
        }

        ConversationMessage::query()
            ->whereIn('id', $outboundMessageIds)
            ->update(['delivered_to_app_at' => $timestamp]);

        $messages->each(function (ConversationMessage $message) use ($outboundMessageIds, $timestamp): void {
            if (in_array($message->id, $outboundMessageIds, true)) {
                $message->delivered_to_app_at = $timestamp;
            }
        });
    }

    private function resolveLastReadMessageId(Conversation $conversation, ?int $lastReadMessageId): ?int
    {
        if ($lastReadMessageId !== null) {
            $existing = $conversation->messages()
                ->where('id', '<=', $lastReadMessageId)
                ->where('direction', MessageDirection::Outbound->value)
                ->where('sender_type', '!=', SenderType::System->value)
                ->max('id');

            if ($existing !== null) {
                return (int) $existing;
            }
        }

        $latest = $conversation->messages()
            ->where('direction', MessageDirection::Outbound->value)
            ->where('sender_type', '!=', SenderType::System->value)
            ->max('id');

        return $latest !== null ? (int) $latest : null;
    }

    private function nullableString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }
}
