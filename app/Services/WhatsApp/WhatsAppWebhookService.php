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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WhatsAppWebhookService
{
    public function __construct(
        private readonly WhatsAppMessageParser    $parser,
        private readonly PhoneNumberService       $phoneService,
        private readonly ConversationManagerService $conversationManager,
    ) {}

    /**
     * Process a full incoming webhook payload.
     * Extracts each message, persists it, and dispatches the processing job.
     *
     * @param  array<string, mixed>  $payload
     */
    private function log(): \Illuminate\Log\LogManager|\Psr\Log\LoggerInterface
    {
        return Log::channel('whatsapp_stack');
    }

    public function handle(array $payload): void
    {
        if (! $this->parser->isValidWebhookPayload($payload)) {
            $this->log()->warning('WhatsAppWebhookService: invalid or unsupported payload structure', [
                'object' => $payload['object'] ?? null,
            ]);
            return;
        }

        $messages = $this->parser->extractMessages($payload);

        $this->log()->info('WhatsAppWebhookService: processing payload', [
            'message_count' => count($messages),
        ]);

        foreach ($messages as $parsedMessage) {
            try {
                $this->processSingleMessage($parsedMessage);
            } catch (\Throwable $e) {
                $this->log()->error('WhatsAppWebhookService: failed to process message', [
                    'wa_message_id' => $parsedMessage['wa_message_id'] ?? null,
                    'from_wa_id'    => $parsedMessage['from_wa_id'] ?? null,
                    'error'         => $e->getMessage(),
                    'file'          => $e->getFile() . ':' . $e->getLine(),
                    'trace'         => $e->getTraceAsString(),
                ]);
            }
        }
    }

    // -------------------------------------------------------------------------
    // Private
    // -------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $parsedMessage
     */
    private function processSingleMessage(array $parsedMessage): void
    {
        $rawWaId   = $parsedMessage['from_wa_id'] ?? null;
        $fromName  = $parsedMessage['from_name'] ?? null;
        $messageId = $parsedMessage['wa_message_id'] ?? null;
        $text      = $parsedMessage['message_text'];
        $type      = $parsedMessage['message_type'] ?? 'text';
        $sentAt    = $parsedMessage['timestamp'];

        if ($rawWaId === null) {
            $this->log()->warning('WhatsAppWebhookService: message has no sender wa_id, skipping.', [
                'wa_message_id' => $messageId,
            ]);
            return;
        }

        $phoneE164 = $this->phoneService->toE164($rawWaId);

        $this->log()->info('WhatsAppWebhookService: message inbound received', [
            'wa_message_id' => $messageId,
            'from'          => $phoneE164,
            'type'          => $type,
            'has_text'      => $text !== null && $text !== '',
        ]);

        DB::transaction(function () use (
            $phoneE164, $fromName, $messageId,
            $text, $type, $sentAt, $parsedMessage
        ): void {
            // 1. Find or create customer
            $customer = $this->findOrCreateCustomer($phoneE164, $fromName);

            // 2. Find or create active conversation
            $conversation = $this->conversationManager->findOrCreateActive($customer);

            // 3. Persist inbound message
            $message = $this->persistMessage(
                conversation : $conversation,
                waMessageId  : $messageId,
                messageType  : $type,
                messageText  : $text,
                sentAt       : $sentAt,
                rawPayload   : $parsedMessage['raw_message'] ?? [],
            );

            // 4. Dispatch job for further processing
            ProcessIncomingWhatsAppMessage::dispatch($message->id, $conversation->id);
        });
    }

    private function findOrCreateCustomer(string $phoneE164, ?string $name): Customer
    {
        /** @var Customer $customer */
        $customer = Customer::firstOrCreate(
            ['phone_e164' => $phoneE164],
            [
                'name'   => $name,
                'status' => 'active',
            ]
        );

        // Update name if we now have one and didn't before
        if ($name !== null && $customer->name === null) {
            $customer->name = $name;
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
            'direction'       => MessageDirection::Inbound,
            'sender_type'     => SenderType::Customer,
            'message_type'    => $messageType,
            'message_text'    => $messageText,
            'raw_payload'     => $rawPayload,
            'wa_message_id'   => $waMessageId,
            'is_fallback'     => false,
            'sent_at'         => $sentAt ?? now(),
        ]);
    }
}
