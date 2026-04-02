<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsAppWebhookDedupEvent;
use Illuminate\Database\QueryException;

class WhatsAppWebhookDedupService
{
    private readonly bool $enabled;

    public function __construct()
    {
        $this->enabled = (bool) config('chatbot.whatsapp.calling.dedup_enabled', true);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{enabled: bool, duplicate: bool, dedup_key: string, payload_hash: string}
     */
    public function claimCallEvent(
        string $dedupKey,
        ?string $waCallId = null,
        array $context = [],
    ): array {
        $payloadHash = $this->payloadHash($context['payload'] ?? []);

        if (! $this->enabled || trim($dedupKey) === '') {
            return [
                'enabled' => $this->enabled,
                'duplicate' => false,
                'dedup_key' => $dedupKey,
                'payload_hash' => $payloadHash,
            ];
        }

        try {
            WhatsAppWebhookDedupEvent::query()->create([
                'event_type' => 'call',
                'dedup_key' => $dedupKey,
                'wa_call_id' => $waCallId,
                'payload_hash' => $payloadHash,
                'trace_id' => (string) ($context['trace_id'] ?? ''),
                'received_at' => now(),
            ]);

            return [
                'enabled' => true,
                'duplicate' => false,
                'dedup_key' => $dedupKey,
                'payload_hash' => $payloadHash,
            ];
        } catch (QueryException $exception) {
            if (! $this->isDuplicateKeyException($exception)) {
                throw $exception;
            }

            return [
                'enabled' => true,
                'duplicate' => true,
                'dedup_key' => $dedupKey,
                'payload_hash' => $payloadHash,
            ];
        }
    }

    private function payloadHash(mixed $payload): string
    {
        if (! is_array($payload)) {
            return sha1((string) $payload);
        }

        return sha1((string) json_encode($payload));
    }

    private function isDuplicateKeyException(QueryException $exception): bool
    {
        $sqlState = $exception->errorInfo[0] ?? null;
        $errorCode = $exception->errorInfo[1] ?? null;
        $message = strtolower($exception->getMessage());

        if (! str_contains($message, 'dedup_key')) {
            return false;
        }

        return in_array($sqlState, ['23000', '23505'], true)
            || in_array($errorCode, [1062, 1555, 2067], true);
    }
}
