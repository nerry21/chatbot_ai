<?php

namespace App\Services\WhatsApp;

use App\Enums\MessageDirection;
use App\Enums\SenderType;
use App\Jobs\ProcessIncomingWhatsAppMessage;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Customer;
use App\Services\Chatbot\ConversationManagerService;
use App\Services\OpenAiChatService;
use App\Services\Support\PhoneNumberService;
use App\Support\WaLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;
use Throwable;

class WhatsAppWebhookService
{
    public function __construct(
        private readonly WhatsAppMessageParser $parser,
        private readonly PhoneNumberService $phoneService,
        private readonly ConversationManagerService $conversationManager,
        private readonly OpenAiChatService $openAiChatService,
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
            } catch (Throwable $e) {
                WaLog::error('[WebhookService] Failed to process single message', [
                    'wa_message_id' => $parsedMessage['wa_message_id'] ?? null,
                    'from' => WaLog::maskPhone((string) ($parsedMessage['from_wa_id'] ?? '')),
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
        $messageId = $this->normalizeWaMessageId($parsedMessage['wa_message_id'] ?? null);
        $text = $parsedMessage['message_text'] ?? null;
        $type = (string) ($parsedMessage['message_type'] ?? 'text');
        $sentAt = $this->parseTimestamp($parsedMessage['timestamp'] ?? null);

        if ($rawWaId === null || trim((string) $rawWaId) === '') {
            WaLog::warning('[WebhookService] Message has no sender wa_id — skipping', [
                'wa_message_id' => $messageId,
                'type' => $type,
            ]);

            return;
        }

        $phoneE164 = $this->phoneService->toE164((string) $rawWaId);

        if ($phoneE164 === '') {
            WaLog::warning('[WebhookService] Failed to normalize sender phone — skipping', [
                'wa_message_id' => $messageId,
                'raw_wa_id' => $rawWaId,
                'type' => $type,
            ]);

            return;
        }

        $existingMessage = $this->findExistingInboundMessage($messageId);

        if ($existingMessage !== null) {
            WaLog::info('[WebhookService] Duplicate inbound skipped — wa_message_id already processed', [
                'wa_message_id' => $messageId,
                'existing_message_id' => $existingMessage->id,
                'existing_conversation_id' => $existingMessage->conversation_id,
                'from' => WaLog::maskPhone($phoneE164),
            ]);

            return;
        }

        WaLog::info('[WebhookService] Inbound message accepted', [
            'wa_message_id' => $messageId,
            'from' => WaLog::maskPhone($phoneE164),
            'type' => $type,
            'has_text' => ! empty($text),
            'text_preview' => $text ? mb_substr((string) $text, 0, 80) : null,
        ]);

        $openAiSeed = $this->buildOpenAiSeed(
            parsedMessage: $parsedMessage,
            messageText: is_string($text) ? $text : null,
            phoneE164: $phoneE164,
            sentAt: $sentAt,
        );

        try {
            DB::transaction(function () use (
                $phoneE164,
                $fromName,
                $messageId,
                $text,
                $type,
                $sentAt,
                $parsedMessage,
                $openAiSeed,
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
                    messageText: $text !== null ? (string) $text : null,
                    sentAt: $sentAt,
                    rawPayload: is_array($parsedMessage['raw_payload'] ?? null)
                        ? $parsedMessage['raw_payload']
                        : (is_array($parsedMessage['raw_message'] ?? null)
                            ? $parsedMessage['raw_message']
                            : []),
                    openAiSeed: $openAiSeed,
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
        } catch (QueryException $e) {
            if ($this->isDuplicateWaMessageException($e, $messageId)) {
                $duplicateMessage = $this->findExistingInboundMessage($messageId);

                WaLog::info('[WebhookService] Duplicate inbound skipped — unique constraint hit', [
                    'wa_message_id' => $messageId,
                    'existing_message_id' => $duplicateMessage?->id,
                    'existing_conversation_id' => $duplicateMessage?->conversation_id,
                    'from' => WaLog::maskPhone($phoneE164),
                ]);

                return;
            }

            throw $e;
        }
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

        if ($name !== null && trim($name) !== '' && $customer->name === null) {
            $customer->name = $name;
            $customer->save();
        }

        if (method_exists($customer, 'touchLastInteraction')) {
            $customer->touchLastInteraction();
        }

        return $customer;
    }

    private function persistMessage(
        Conversation $conversation,
        ?string $waMessageId,
        string $messageType,
        ?string $messageText,
        ?Carbon $sentAt,
        array $rawPayload,
        array $openAiSeed = [],
    ): ConversationMessage {
        $intentPreview = is_array($openAiSeed['intent'] ?? null) ? $openAiSeed['intent'] : [];
        $aiIntent = $this->normalizeAiIntent($intentPreview['intent'] ?? null);
        $aiConfidence = $this->normalizeAiConfidence($intentPreview['confidence'] ?? null);
        $enrichedPayload = $rawPayload;

        if ($openAiSeed !== []) {
            $enrichedPayload['_openai_seed'] = $openAiSeed;
        }

        /** @var ConversationMessage $message */
        $message = ConversationMessage::create([
            'conversation_id' => $conversation->id,
            'direction' => MessageDirection::Inbound,
            'sender_type' => SenderType::Customer,
            'message_type' => $messageType,
            'message_text' => $messageText,
            'raw_payload' => $enrichedPayload,
            'wa_message_id' => $waMessageId,
            'ai_intent' => $aiIntent,
            'ai_confidence' => $aiConfidence,
            'is_fallback' => false,
            'sent_at' => $sentAt ?? now(),
        ]);

        return $message;
    }

    private function parseTimestamp(mixed $timestamp): ?Carbon
    {
        if ($timestamp instanceof Carbon) {
            return $timestamp;
        }

        if (is_int($timestamp) || (is_string($timestamp) && ctype_digit($timestamp))) {
            return Carbon::createFromTimestamp((int) $timestamp);
        }

        if (is_string($timestamp) && trim($timestamp) !== '') {
            try {
                return Carbon::parse($timestamp);
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }

    private function normalizeWaMessageId(mixed $waMessageId): ?string
    {
        if (! is_string($waMessageId) && ! is_numeric($waMessageId)) {
            return null;
        }

        $normalized = trim((string) $waMessageId);

        return $normalized !== '' ? $normalized : null;
    }

    private function findExistingInboundMessage(?string $waMessageId): ?ConversationMessage
    {
        if ($waMessageId === null) {
            return null;
        }

        return ConversationMessage::query()
            ->where('wa_message_id', $waMessageId)
            ->first(['id', 'conversation_id', 'wa_message_id']);
    }

    private function isDuplicateWaMessageException(QueryException $e, ?string $waMessageId): bool
    {
        if ($waMessageId === null) {
            return false;
        }

        $sqlState = $e->errorInfo[0] ?? null;
        $errorCode = $e->errorInfo[1] ?? null;
        $message = strtolower($e->getMessage());

        if (! str_contains($message, 'wa_message_id')) {
            return false;
        }

        return in_array($sqlState, ['23000', '23505'], true)
            || in_array($errorCode, [1062, 1555, 2067], true);
    }

    /**
     * @param  array<string, mixed>  $parsedMessage
     * @return array<string, mixed>
     */
    private function buildOpenAiSeed(
        array $parsedMessage,
        ?string $messageText,
        string $phoneE164,
        ?Carbon $sentAt,
    ): array {
        if (! $this->shouldBuildOpenAiSeed($messageText)) {
            return [];
        }

        $context = array_filter([
            'channel' => 'whatsapp',
            'message_type' => $parsedMessage['message_type'] ?? 'text',
            'from_name' => $parsedMessage['from_name'] ?? null,
            'sent_at' => $sentAt?->toIso8601String(),
            'interactive_reply' => is_array($parsedMessage['interactive_reply'] ?? null)
                ? $parsedMessage['interactive_reply']
                : null,
            'metadata' => is_array($parsedMessage['metadata'] ?? null)
                ? $parsedMessage['metadata']
                : null,
        ], static fn (mixed $value): bool => $value !== null && $value !== []);

        try {
            $intent = $this->openAiChatService->detectIntent($messageText, $context);
            $bookingData = $this->openAiChatService->extractBookingData($messageText, $context);

            WaLog::debug('[WebhookService] OpenAI seed built for inbound message', [
                'intent' => $intent['intent'] ?? null,
                'confidence' => $intent['confidence'] ?? null,
                'has_booking_data' => array_filter(
                    $bookingData,
                    static fn (mixed $value): bool => $value !== null && $value !== ''
                ) !== [],
            ]);

            return [
                'source' => OpenAiChatService::class,
                'seeded_at' => now()->toIso8601String(),
                'intent' => $intent,
                'booking_data' => $bookingData,
            ];
        } catch (Throwable $e) {
            WaLog::warning('[WebhookService] OpenAI seed skipped after recoverable failure', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    private function shouldBuildOpenAiSeed(?string $messageText): bool
    {
        if (! config('openai.enabled', true)) {
            return false;
        }

        if (! config('openai.seed_on_webhook', true)) {
            return false;
        }

        if (trim((string) $messageText) === '') {
            return false;
        }

        return trim((string) config('services.openai.api_key', '')) !== '';
    }

    private function normalizeAiIntent(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $intent = trim((string) $value);

        return $intent !== '' ? $intent : null;
    }

    private function normalizeAiConfidence(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        $confidence = (float) $value;
        $confidence = max(0.0, min(1.0, $confidence));

        return $confidence;
    }
}
