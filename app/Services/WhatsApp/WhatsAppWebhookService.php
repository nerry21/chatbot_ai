<?php

namespace App\Services\WhatsApp;

use App\Enums\MessageDirection;
use App\Enums\SenderType;
use App\Jobs\ProcessIncomingWhatsAppMessage;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Customer;
use App\Services\Chatbot\ConversationManagerService;
use App\Services\Support\PhoneNumberService;
use App\Support\WaLog;
use Illuminate\Support\Facades\DB;

class WhatsAppWebhookService
{
    public function __construct(
        private readonly WhatsAppMessageParser $parser,
        private readonly PhoneNumberService $phoneService,
        private readonly ConversationManagerService $conversationManager,
    ) {
    }

    /**
     * Process a full incoming webhook payload.
     *
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload): void
    {
        if (! $this->parser->isValidWebhookPayload($payload)) {
            WaLog::warning('[WebhookService] Invalid or unsupported payload structure', [
                'object' => $payload['object'] ?? null,
            ]);
            return;
        }

        $messages = $this->parser->extractMessages($payload);

        WaLog::info('[WebhookService] Payload parsed — processing messages', [
            'message_count' => count($messages),
        ]);

        foreach ($messages as $parsedMessage) {
            try {
                $this->processSingleMessage($parsedMessage);
            } catch (\Throwable $e) {
                WaLog::error('[WebhookService] Failed to process single message', [
                    'wa_message_id' => $parsedMessage['wa_message_id'] ?? null,
                    'from' => WaLog::maskPhone($parsedMessage['from_wa_id'] ?? ''),
                    'error' => $e->getMessage(),
                    'file' => $e->getFile() . ':' . $e->getLine(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }
    }

    /**
     * @param array<string, mixed> $parsedMessage
     */
    private function processSingleMessage(array $parsedMessage): void
    {
        $rawWaId = $parsedMessage['from_wa_id'] ?? null;
        $fromName = $parsedMessage['from_name'] ?? null;
        $messageId = $parsedMessage['wa_message_id'] ?? null;
        $text = $parsedMessage['message_text'] ?? null;
        $type = $parsedMessage['message_type'] ?? 'text';
        $sentAt = $parsedMessage['timestamp'] ?? null;

        if ($rawWaId === null) {
            WaLog::warning('[WebhookService] Message has no sender wa_id — skipping', [
                'wa_message_id' => $messageId,
                'type' => $type,
            ]);
            return;
        }

        $phoneE164 = $this->phoneService->toE164($rawWaId);

        WaLog::info('[WebhookService] Inbound message accepted', [
            'wa_message_id' => $messageId,
            'from' => WaLog::maskPhone($phoneE164),
            'type' => $type,
            'has_text' => ! empty($text),
            'text_preview' => $text ? mb_substr((string) $text, 0, 80) : null,
        ]);

        DB::transaction(function () use (
            $phoneE164,
            $fromName,
            $messageId,
            $text,
            $type,
            $sentAt,
            $parsedMessage
        ): void {
            $customer = $this->findOrCreateCustomer($phoneE164, $fromName);

            WaLog::debug('[WebhookService] Customer resolved', [
                'customer_id' => $customer->id,
                'is_new' => $customer->wasRecentlyCreated,
                'phone' => WaLog::maskPhone($phoneE164),
            ]);

            $conversation = $this->conversationManager->findOrCreateActive($customer);

            WaLog::debug('[WebhookService] Conversation resolved', [
                'conversation_id' => $conversation->id,
                'status' => $conversation->status?->value ?? null,
            ]);

            $message = $this->persistMessage(
                conversation: $conversation,
                waMessageId: $messageId,
                messageType: $type,
                messageText: $text,
                sentAt: $sentAt,
                rawPayload: $parsedMessage['raw_message'] ?? [],
            );

            WaLog::info('[WebhookService] Inbound message persisted', [
                'message_id' => $message->id,
                'conversation_id' => $conversation->id,
                'customer_id' => $customer->id,
            ]);

            ProcessIncomingWhatsAppMessage::dispatch(
                $message->id,
                $conversation->id,
                WaLog::traceId(),
            );

            WaLog::debug('[WebhookService] ProcessIncomingWhatsAppMessage dispatched', [
                'message_id' => $message->id,
                'conversation_id' => $conversation->id,
            ]);
        });
    }

    private function findOrCreateCustomer(string $phoneE164, ?string $name): Customer
    {
        /** @var Customer $customer */
        $customer = Customer::firstOrCreate(
            ['phone_e164' => $phoneE164],
            [
                'name' => $name,
                'status' => 'active',
            ]
        );

        if ($name !== null && $customer->name === null) {
            $customer->name = $name;
            $customer->save();
        }

        $customer->touchLastInteraction();

        return $customer;
    }

    private function persistMessage(
        Conversation $conversation,
        ?string $waMessageId,
        string $messageType,
        ?string $messageText,
        ?\Carbon\Carbon $sentAt,
        array $rawPayload,
    ): ConversationMessage {
        return ConversationMessage::create([
            'conversation_id' => $conversation->id,
            'direction' => MessageDirection::Inbound,
            'sender_type' => SenderType::Customer,
            'message_type' => $messageType,
            'message_text' => $messageText,
            'raw_payload' => $rawPayload,
            'wa_message_id' => $waMessageId,
            'is_fallback' => false,
            'sent_at' => $sentAt ?? now(),
        ]);
    }
}