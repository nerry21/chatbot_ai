<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsAppWebhookDedupEvent;
use App\Support\WaLog;
use Illuminate\Database\QueryException;

class WhatsAppWebhookDedupService
{
    private readonly bool $enabled;

    public function __construct()
    {
        $this->enabled = (bool) config('chatbot.whatsapp.calling.dedup_enabled', true);
    }

    // ─── Public API ────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $parsedMessage
     * @return array<string, mixed>
     */
    public function claimIncomingMessage(array $parsedMessage): array
    {
        $dedupKey = $this->buildIncomingMessageDedupKey($parsedMessage);

        return $this->claimDedupKey(
            eventType: 'incoming_message',
            dedupKey: $dedupKey,
            payload: $parsedMessage,
        );
    }

    /**
     * @param  array<string, mixed>  $parsedStatus
     * @return array<string, mixed>
     */
    public function claimStatusEvent(array $parsedStatus): array
    {
        $dedupKey = $this->buildStatusDedupKey($parsedStatus);

        return $this->claimDedupKey(
            eventType: 'status',
            dedupKey: $dedupKey,
            payload: $parsedStatus,
        );
    }

    /**
     * @param  array<string, mixed>  $parsedCall
     * @return array<string, mixed>
     */
    public function claimCallEvent(array $parsedCall): array
    {
        $dedupKey = $this->buildCallDedupKey($parsedCall);

        return $this->claimDedupKey(
            eventType: 'call',
            dedupKey: $dedupKey,
            payload: $parsedCall,
        );
    }

    public function pruneOldDedupRecords(int $hours = 72): int
    {
        return WhatsAppWebhookDedupEvent::query()
            ->where('received_at', '<', now()->subHours($hours))
            ->delete();
    }

    // ─── Result Builder ────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    private function dedupResult(
        bool $duplicate,
        string $eventType,
        ?string $dedupKey,
        array $details = [],
    ): array {
        return [
            'duplicate' => $duplicate,
            'event_type' => $eventType,
            'dedup_key' => $dedupKey,
            'details' => $details,
        ];
    }

    // ─── Key Normalizer ────────────────────────────────────────────────────────

    private function normalizeEventKey(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    // ─── Key Builders ──────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $parsedMessage
     */
    private function buildIncomingMessageDedupKey(array $parsedMessage): ?string
    {
        $waMessageId = $this->normalizeEventKey(
            $parsedMessage['wa_message_id']
                ?? $parsedMessage['message_id']
                ?? null
        );

        if ($waMessageId === null) {
            return null;
        }

        $direction = $this->normalizeEventKey($parsedMessage['direction'] ?? 'inbound') ?? 'inbound';

        return 'incoming_message:'.$direction.':'.$waMessageId;
    }

    /**
     * @param  array<string, mixed>  $parsedStatus
     */
    private function buildStatusDedupKey(array $parsedStatus): ?string
    {
        $waMessageId = $this->normalizeEventKey($parsedStatus['wa_message_id'] ?? null);
        $status = $this->normalizeEventKey($parsedStatus['status'] ?? null);

        if ($waMessageId === null || $status === null) {
            return null;
        }

        return 'status:'.$waMessageId.':'.$status;
    }

    /**
     * @param  array<string, mixed>  $parsedCall
     */
    private function buildCallDedupKey(array $parsedCall): ?string
    {
        $callId = $this->normalizeEventKey(
            $parsedCall['call_id']
                ?? $parsedCall['id']
                ?? null
        );

        if ($callId !== null) {
            return 'call:'.$callId;
        }

        $from = $this->normalizeEventKey($parsedCall['from'] ?? null);
        $to = $this->normalizeEventKey($parsedCall['to'] ?? null);
        $timestamp = $this->normalizeEventKey($parsedCall['timestamp'] ?? null);

        if ($from === null || $to === null || $timestamp === null) {
            return null;
        }

        return 'call:'.$from.':'.$to.':'.$timestamp;
    }

    // ─── Claim Core ────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function claimDedupKey(string $eventType, ?string $dedupKey, array $payload = []): array
    {
        if (! $this->enabled) {
            return $this->dedupResult(
                duplicate: false,
                eventType: $eventType,
                dedupKey: $dedupKey,
                details: ['reason' => 'dedup_disabled'],
            );
        }

        if ($dedupKey === null) {
            return $this->dedupResult(
                duplicate: false,
                eventType: $eventType,
                dedupKey: null,
                details: ['reason' => 'missing_dedup_key'],
            );
        }

        try {
            $inserted = $this->insertDedupRecord($eventType, $dedupKey, $payload);

            WaLog::info('[WhatsAppWebhookDedupService] Dedup key processed', [
                'event_type' => $eventType,
                'dedup_key' => $dedupKey,
                'duplicate' => ! $inserted,
            ]);

            return $this->dedupResult(
                duplicate: ! $inserted,
                eventType: $eventType,
                dedupKey: $dedupKey,
                details: ['claimed' => $inserted],
            );
        } catch (QueryException $e) {
            if ($this->isDuplicateKeyException($e)) {
                WaLog::info('[WhatsAppWebhookDedupService] Duplicate webhook event detected', [
                    'event_type' => $eventType,
                    'dedup_key' => $dedupKey,
                ]);

                return $this->dedupResult(
                    duplicate: true,
                    eventType: $eventType,
                    dedupKey: $dedupKey,
                    details: [
                        'claimed' => false,
                        'reason' => 'duplicate_key_exception',
                    ],
                );
            }

            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function insertDedupRecord(string $eventType, string $dedupKey, array $payload = []): bool
    {
        $payloadHash = $this->payloadHash($payload);

        $now = now();

        $inserted = WhatsAppWebhookDedupEvent::query()->insertOrIgnore([[
            'event_type'   => $eventType,
            'dedup_key'    => $dedupKey,
            'wa_call_id'   => $this->normalizeEventKey($payload['call_id'] ?? $payload['id'] ?? null),
            'payload_hash' => $payloadHash,
            'trace_id'     => WaLog::traceId(),
            'received_at'  => $now,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]]);

        return $inserted > 0;
    }

    // ─── Helpers ───────────────────────────────────────────────────────────────

    private function payloadHash(mixed $payload): string
    {
        if (is_array($payload)) {
            ksort($payload);
            $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } else {
            $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return sha1((string) $encoded);
    }

    private function isDuplicateKeyException(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'duplicate')
            || str_contains($message, 'unique constraint')
            || str_contains($message, 'integrity constraint violation')
            || str_contains($message, '1062');
    }
}
