<?php

namespace App\Services\WhatsApp;

use App\Enums\MessageDeliveryStatus;
use App\Enums\MessageDirection;
use App\Jobs\ProcessIncomingWhatsAppMessage;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Customer;
use App\Services\Chatbot\ConversationManagerService;
use App\Services\Firebase\FcmNotificationService;
use App\Services\Support\PhoneNumberService;
use App\Support\WaLog;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

class WhatsAppWebhookService
{
    public function __construct(
        private readonly WhatsAppMessageParser $parser,
        private readonly WhatsAppCallWebhookService $callWebhookService,
        private readonly PhoneNumberService $phoneService,
        private readonly ConversationManagerService $conversationManager,
        private readonly WhatsAppWebhookDedupService $dedupService,
        private readonly FcmNotificationService $fcmNotificationService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    private function blankWebhookReport(string $traceId): array
    {
        return [
            'trace_id' => $traceId,
            'status' => 'processed',
            'message_count' => 0,
            'status_count' => 0,
            'call_count' => 0,
            'queued_jobs' => 0,
            'duplicates' => 0,
            'errors' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function appendWebhookError(array $report, string $message): array
    {
        $report['errors'][] = $message;
        $report['status'] = 'partial';

        return $report;
    }

    private function buildWebhookTraceId(): string
    {
        return 'wa-webhook-'.now()->format('YmdHis').'-'.substr(md5((string) microtime(true)), 0, 8);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *     messages: array<int, array<string, mixed>>,
     *     statuses: array<int, array<string, mixed>>,
     *     calls: array<int, array<string, mixed>>
     * }
     */
    private function parseWebhookPayload(array $payload): array
    {
        if (! $this->parser->isValidWebhookPayload($payload)) {
            return [
                'messages' => [],
                'statuses' => [],
                'calls' => [],
            ];
        }

        $messages = $this->parser->extractMessages($payload);
        $statuses = $this->parser->extractStatuses($payload);
        $calls = $this->parser->extractCalls($payload);

        return [
            'messages' => is_array($messages ?? null) ? $messages : [],
            'statuses' => is_array($statuses ?? null) ? $statuses : [],
            'calls' => is_array($calls ?? null) ? $calls : [],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $statuses
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function processStatusesBatch(array $statuses, array $report): array
    {
        foreach ($statuses as $parsedStatus) {
            try {
                $this->processSingleStatus($parsedStatus);
                $report['status_count']++;
            } catch (\Throwable $e) {
                $report = $this->appendWebhookError(
                    $report,
                    '[status] '.$e->getMessage(),
                );
            }
        }

        return $report;
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function processMessagesBatch(array $messages, array $report): array
    {
        foreach ($messages as $parsedMessage) {
            try {
                $result = $this->processSingleMessage($parsedMessage);

                $report['message_count']++;

                if (($result['duplicate'] ?? false) === true) {
                    $report['duplicates']++;
                }

                if (($result['queued'] ?? false) === true) {
                    $report['queued_jobs']++;
                }
            } catch (\Throwable $e) {
                $report = $this->appendWebhookError(
                    $report,
                    '[message] '.$e->getMessage(),
                );
            }
        }

        return $report;
    }

    /**
     * @param  array<int, array<string, mixed>>  $calls
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function processCallsBatch(array $calls, array $report): array
    {
        foreach ($calls as $parsedCall) {
            try {
                $this->processSingleCall($parsedCall);
                $report['call_count']++;
            } catch (\Throwable $e) {
                $report = $this->appendWebhookError(
                    $report,
                    '[call] '.$e->getMessage(),
                );
            }
        }

        return $report;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *     trace_id: string,
     *     status: string,
     *     message_count: int,
     *     status_count: int,
     *     call_count: int,
     *     queued_jobs: int,
     *     duplicates: int,
     *     errors: array<int, string>
     * }
     */
    public function handle(array $payload): array
    {
        $traceId = $this->buildWebhookTraceId();
        $report = $this->blankWebhookReport($traceId);

        try {
            $parsed = $this->parseWebhookPayload($payload);

            WaLog::info('[WebhookService] Payload parsed - processing webhook events', [
                'message_count' => count($parsed['messages']),
                'status_count' => count($parsed['statuses']),
                'call_count' => count($parsed['calls']),
                '_trace' => $traceId,
                '_source' => 'WhatsAppWebhookService::handle',
            ]);

            $report = $this->processStatusesBatch($parsed['statuses'], $report);
            $report = $this->processMessagesBatch($parsed['messages'], $report);
            $report = $this->processCallsBatch($parsed['calls'], $report);

            if ($report['errors'] === []) {
                $report['status'] = 'processed';
            }

            return $report;
        } catch (\Throwable $e) {
            return [
                'trace_id' => $traceId,
                'status' => 'failed',
                'message_count' => $report['message_count'],
                'status_count' => $report['status_count'],
                'call_count' => $report['call_count'],
                'queued_jobs' => $report['queued_jobs'],
                'duplicates' => $report['duplicates'],
                'errors' => [$e->getMessage()],
            ];
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $callEvents
     * @param  array<string, mixed>  $context
     * @return array<int, array<string, mixed>>
     */
    public function handleCalls(array $callEvents, array $context = []): array
    {
        $results = [];

        foreach ($callEvents as $callEvent) {
            try {
                $results[] = $this->callWebhookService->handleCallEvent($callEvent, $context);
            } catch (Throwable $e) {
                $debugPayload = $this->callWebhookService->buildDebugPayload($callEvent);

                WaLog::error('[WebhookService] Failed to process call event', [
                    'wa_call_id' => $debugPayload['wa_call_id'] ?? null,
                    'event' => $debugPayload['event'] ?? null,
                    'error' => $e->getMessage(),
                    'file' => $e->getFile() . ':' . $e->getLine(),
                ]);

                $results[] = [
                    'result' => 'error',
                    'wa_call_id' => $debugPayload['wa_call_id'] ?? null,
                    'local_status' => null,
                    'error' => $e->getMessage(),
                    'debug' => $debugPayload,
                ];
            }
        }

        return $results;
    }

    /**
     * @param array<string, mixed> $parsedStatus
     */
    private function processSingleStatus(array $parsedStatus): void
    {
        $dedup = $this->dedupService->claimStatusEvent($parsedStatus);

        if (($dedup['duplicate'] ?? false) === true) {
            WaLog::info('[WebhookService] Status event skipped as duplicate', [
                'dedup_key' => $dedup['dedup_key'] ?? null,
            ]);

            return;
        }

        $waMessageId = $this->normalizeWaMessageId($parsedStatus['wa_message_id'] ?? null);
        $status = trim((string) ($parsedStatus['status'] ?? ''));
        $timestamp = $this->parseTimestamp($parsedStatus['timestamp'] ?? null);

        if ($waMessageId === null || $status === '') {
            return;
        }

        $message = ConversationMessage::query()
            ->where('wa_message_id', $waMessageId)
            ->where('direction', MessageDirection::Outbound->value)
            ->first();

        if (! $message instanceof ConversationMessage) {
            WaLog::warning('[WebhookService] Outbound status received for unknown message', [
                'wa_message_id' => $waMessageId,
                'status' => $status,
            ]);

            return;
        }

        $rawPayload = is_array($message->raw_payload) ? $message->raw_payload : [];
        $rawPayload['wa_webhook_status'] = [
            'status' => $status,
            'timestamp' => $timestamp?->toIso8601String(),
            'recipient_id' => $parsedStatus['recipient_id'] ?? null,
            'conversation' => $parsedStatus['conversation'] ?? null,
            'pricing' => $parsedStatus['pricing'] ?? null,
            'errors' => is_array($parsedStatus['errors'] ?? null) ? $parsedStatus['errors'] : [],
            'metadata' => is_array($parsedStatus['metadata'] ?? null) ? $parsedStatus['metadata'] : [],
            'raw_status' => $parsedStatus['raw_status'] ?? null,
        ];

        if ($status === 'sent') {
            $message->markSent($waMessageId, $rawPayload);
            $message->forceFill([
                'delivery_status' => MessageDeliveryStatus::Sent,
                'sent_at' => $timestamp ?? ($message->sent_at ?? now()),
                'raw_payload' => $rawPayload,
            ])->save();

            return;
        }

        if ($status === 'delivered') {
            $message->markDelivered($timestamp ?? now(), $rawPayload);

            return;
        }

        if ($status === 'read') {
            if ($message->delivery_status !== MessageDeliveryStatus::Delivered) {
                $message->markDelivered($timestamp ?? now(), $rawPayload);
            }

            $message->markRead($timestamp ?? now());
            $message->forceFill([
                'raw_payload' => $rawPayload,
            ])->save();

            return;
        }

        if ($status === 'failed') {
            $errorText = $this->formatStatusErrors(
                is_array($parsedStatus['errors'] ?? null) ? $parsedStatus['errors'] : []
            );

            $message->markFailed($errorText, $rawPayload);
        }
    }

    /**
     * @param  array<string, mixed>  $parsedCall
     */
    private function processSingleCall(array $parsedCall): void
    {
        $dedup = $this->dedupService->claimCallEvent($parsedCall);

        if (($dedup['duplicate'] ?? false) === true) {
            WaLog::info('[WebhookService] Call event skipped as duplicate', [
                'dedup_key' => $dedup['dedup_key'] ?? null,
            ]);

            return;
        }

        $context = [
            '_source' => 'WhatsAppWebhookService::processSingleCall',
            '_trace' => WaLog::traceId(),
        ];

        $this->callWebhookService->handleCallEvent($parsedCall, $context);
    }

    /**
     * @param  array<string, mixed>  $parsedMessage
     * @return array<string, mixed>
     */
    private function claimMessageDedup(array $parsedMessage): array
    {
        if (! config('chatbot.webhook.dedup_enabled', true)) {
            return ['duplicate' => false];
        }

        return $this->dedupService->claimIncomingMessage($parsedMessage);
    }

    /**
     * @param  mixed $persisted
     */
    private function dispatchIncomingMessageJob(mixed $persisted): void
    {
        if ($persisted === null) {
            return;
        }

        if (
            isset($persisted->conversation_id)
            && isset($persisted->id)
        ) {
            ProcessIncomingWhatsAppMessage::dispatch(
                $persisted->id,
                $persisted->conversation_id,
                WaLog::traceId(),
            );
        }
    }

    /**
     * @param  array<string, mixed>  $parsedMessage
     * @return mixed
     */
    private function persistIncomingMessage(array $parsedMessage): mixed
    {
        try {
            return DB::transaction(function () use ($parsedMessage) {
                $rawWaId = $parsedMessage['from_wa_id'] ?? null;
                $fromName = $parsedMessage['from_name'] ?? null;
                $messageId = $this->normalizeWaMessageId($parsedMessage['wa_message_id'] ?? null);
                $text = $parsedMessage['message_text'] ?? null;
                $type = (string) ($parsedMessage['message_type'] ?? 'text');
                $sentAt = $this->parseTimestamp($parsedMessage['timestamp'] ?? null);

                if ($messageId === null || $rawWaId === null) {
                    return null;
                }

                $phoneE164 = $this->phoneService->toE164((string) $rawWaId);

                if ($phoneE164 === '') {
                    return null;
                }

                $ingressSeed = $this->buildIngressSeed(
                    parsedMessage: $parsedMessage,
                    messageText: is_string($text) ? $text : null,
                    sentAt: $sentAt,
                );

                $customer = $this->findOrCreateCustomer($phoneE164, is_string($fromName) ? $fromName : null);
                $conversation = $this->conversationManager->findOrCreateActive($customer);

                return $this->persistMessage(
                    conversation: $conversation,
                    waMessageId: $messageId,
                    messageType: $type,
                    messageText: $text !== null ? (string) $text : null,
                    sentAt: $sentAt,
                    rawPayload: is_array($parsedMessage['raw_payload'] ?? null)
                        ? $parsedMessage['raw_payload']
                        : (is_array($parsedMessage['raw_message'] ?? null) ? $parsedMessage['raw_message'] : []),
                    ingressSeed: $ingressSeed,
                );
            });
        } catch (QueryException $e) {
            $messageId = $this->normalizeWaMessageId($parsedMessage['wa_message_id'] ?? null);

            if ($this->isDuplicateWaMessageException($e, $messageId)) {
                return null;
            }

            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $parsedMessage
     * @return array<string, mixed>
     */
    private function processSingleMessage(array $parsedMessage): array
    {
        $rawWaId = $parsedMessage['from_wa_id'] ?? null;
        $fromName = $parsedMessage['from_name'] ?? null;
        $messageId = $this->normalizeWaMessageId($parsedMessage['wa_message_id'] ?? null);
        $text = $parsedMessage['message_text'] ?? null;
        $type = (string) ($parsedMessage['message_type'] ?? 'text');
        $sentAt = $this->parseTimestamp($parsedMessage['timestamp'] ?? null);

        if ($messageId === null) {
            WaLog::warning('[WebhookService] Message skipped because wa_message_id is missing', [
                'from' => WaLog::maskPhone((string) ($rawWaId ?? '')),
                'type' => $type,
            ]);

            return [
                'queued' => false,
                'duplicate' => false,
            ];
        }

        if ($rawWaId === null || trim((string) $rawWaId) === '') {
            WaLog::warning('[WebhookService] Message has no sender wa_id - skipping', [
                'wa_message_id' => $messageId,
                'type' => $type,
            ]);

            return [
                'queued' => false,
                'duplicate' => false,
            ];
        }

        $phoneE164 = $this->phoneService->toE164((string) $rawWaId);

        if ($phoneE164 === '') {
            WaLog::warning('[WebhookService] Failed to normalize sender phone - skipping', [
                'wa_message_id' => $messageId,
                'raw_wa_id' => $rawWaId,
                'type' => $type,
            ]);

            return [
                'queued' => false,
                'duplicate' => false,
            ];
        }

        // Dedup claim (configurable + service hook + DB check)
        $dedupResult = $this->claimMessageDedup($parsedMessage);

        if (($dedupResult['duplicate'] ?? false) === true || $this->findExistingInboundMessage($messageId) !== null) {
            WaLog::info('[WebhookService] Incoming message skipped as duplicate', [
                'wa_message_id' => $messageId,
            ]);

            return [
                'queued' => false,
                'duplicate' => true,
            ];
        }

        // Persist inbound
        $persisted = $this->persistIncomingMessage($parsedMessage);

        if ($persisted === null) {
            return [
                'queued' => false,
                'duplicate' => false,
            ];
        }

        // Enqueue downstream job
        $this->dispatchIncomingMessageJob($persisted);

        // ─── Trigger push notification ke admin ────────────────────────
        try {
            $conversation = \App\Models\Conversation::with('customer')->find($persisted->conversation_id);
            if ($conversation !== null) {
                $this->fcmNotificationService->notifyIncomingMessage(
                    message: $persisted,
                    conversation: $conversation,
                    customer: $conversation->customer,
                );
            }
        } catch (\Throwable $e) {
            // Push gagal tidak boleh mengganggu webhook flow.
            WaLog::warning('[WebhookService] FCM push failed (non-fatal)', [
                'error' => $e->getMessage(),
            ]);
        }

        WaLog::info('[WebhookService] Incoming message queued for processing', [
            'wa_message_id' => $parsedMessage['wa_message_id'] ?? null,
            'conversation_id' => $persisted->conversation_id ?? null,
            'message_id' => $persisted->id ?? null,
        ]);

        return [
            'queued' => true,
            'duplicate' => false,
        ];
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
        array $ingressSeed = [],
    ): ConversationMessage {
        $enrichedPayload = $rawPayload;

        if ($ingressSeed !== []) {
            $enrichedPayload['_ingress_seed'] = $ingressSeed;
        }

        $message = $this->conversationManager->appendInboundMessage($conversation, [
            'message_type' => $messageType,
            'message_text' => $messageText,
            'raw_payload' => $enrichedPayload,
            'channel_message_id' => $waMessageId,
            'wa_message_id' => $waMessageId,
            'sent_at' => $sentAt ?? now(),
        ]);

        $message->forceFill([
            'ai_intent' => null,
            'ai_confidence' => null,
            'delivery_status' => MessageDeliveryStatus::Sent,
            'is_fallback' => false,
        ])->save();

        $conversation->forceFill([
            'last_message_at' => $sentAt ?? now(),
        ])->save();

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
     * Webhook layer tidak lagi menjalankan LLM.
     * Method ini hanya membuat metadata ingress ringan agar seluruh reasoning AI
     * tetap terpusat di ProcessIncomingWhatsAppMessage.
     *
     * @param array<string, mixed> $parsedMessage
     * @return array<string, mixed>
     */
    private function buildIngressSeed(
        array $parsedMessage,
        ?string $messageText,
        ?Carbon $sentAt,
    ): array {
        return array_filter([
            'source' => 'whatsapp_webhook_ingress',
            'seeded_at' => now()->toIso8601String(),
            'channel' => 'whatsapp',
            'message_type' => $parsedMessage['message_type'] ?? 'text',
            'from_name' => $parsedMessage['from_name'] ?? null,
            'sent_at' => $sentAt?->toIso8601String(),
            'has_text' => trim((string) $messageText) !== '',
            'text_preview' => trim((string) $messageText) !== ''
                ? mb_substr(trim((string) $messageText), 0, 120)
                : null,
            'interactive_reply' => is_array($parsedMessage['interactive_reply'] ?? null)
                ? $parsedMessage['interactive_reply']
                : null,
            'metadata' => is_array($parsedMessage['metadata'] ?? null)
                ? $parsedMessage['metadata']
                : null,
        ], static fn (mixed $value): bool => $value !== null && $value !== []);
    }

    /**
     * @param array<int, array<string, mixed>> $errors
     */
    private function formatStatusErrors(array $errors): string
    {
        $parts = [];

        foreach ($errors as $error) {
            $code = $error['code'] ?? null;
            $title = trim((string) ($error['title'] ?? ''));
            $message = trim((string) ($error['message'] ?? ''));
            $details = trim((string) data_get($error, 'error_data.details', ''));

            $piece = collect([
                $code !== null ? '[' . $code . ']' : null,
                $title !== '' ? $title : null,
                $message !== '' ? $message : null,
                $details !== '' ? $details : null,
            ])->filter()->implode(' ');

            if ($piece !== '') {
                $parts[] = $piece;
            }
        }

        return $parts !== [] ? implode(' | ', $parts) : 'WhatsApp provider reported failed status.';
    }
}
