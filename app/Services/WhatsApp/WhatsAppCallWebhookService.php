<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsAppCallSession;
use App\Services\Support\PhoneNumberService;
use App\Support\WaLog;
use Illuminate\Support\Arr;

class WhatsAppCallWebhookService
{
    private const MAX_STORED_WEBHOOK_EVENTS = 12;

    public function __construct(
        private readonly WhatsAppCallSessionService $callSessionService,
        private readonly PhoneNumberService $phoneService,
        private readonly WhatsAppWebhookDedupService $webhookDedupService,
        private readonly WhatsAppCallAuditService $auditService,
    ) {}

    /**
     * @param  array<string, mixed>  $callEvent
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function handleCallEvent(array $callEvent, array $context = []): array
    {
        $callId = $this->extractCallId($callEvent);
        $customerWaId = $this->extractCustomerWaId($callEvent);
        $terminationReason = $this->extractTerminationReason($callEvent);
        $localStatus = $this->mapMetaCallEventToLocalStatus($callEvent);
        $debugPayload = $this->buildDebugPayload($callEvent);
        $dedupKey = $this->buildDedupKey($callEvent, $localStatus);
        $dedup = $this->webhookDedupService->claimCallEvent($dedupKey, $callId, [
            'trace_id' => $context['trace_id'] ?? WaLog::traceId(),
            'payload' => $debugPayload,
        ]);

        $this->auditService->info('webhook_call_received', [
            'conversation_id' => $context['conversation_id'] ?? null,
            'wa_call_id' => $callId,
            'action' => 'webhook_call',
            'result' => 'received',
            'status_after' => $localStatus,
            'dedup_key' => $dedup['dedup_key'],
        ]);

        if (($dedup['duplicate'] ?? false) === true) {
            $result = [
                'result' => 'ignored_duplicate',
                'wa_call_id' => $callId,
                'customer_wa_id' => $customerWaId,
                'local_status' => $localStatus,
                'termination_reason' => $terminationReason,
                'debug' => $debugPayload,
            ];

            $this->auditService->info('webhook_call_ignored_duplicate', [
                'wa_call_id' => $callId,
                'result' => 'ignored_duplicate',
                'status_after' => $localStatus,
                'dedup_key' => $dedup['dedup_key'],
            ]);

            WaLog::info('[CallWebhook] Call event ignored as duplicate', $result);

            return $result;
        }

        if ($localStatus === null) {
            $result = [
                'result' => 'ignored_unknown_status',
                'wa_call_id' => $callId,
                'customer_wa_id' => $customerWaId,
                'local_status' => null,
                'termination_reason' => $terminationReason,
                'debug' => $debugPayload,
            ];

            $this->auditService->warning('webhook_call_unmatched', [
                'wa_call_id' => $callId,
                'result' => 'ignored_unknown_status',
            ]);

            WaLog::warning('[CallWebhook] Call event ignored because status mapping is unknown', $result);

            return $result;
        }

        $session = $this->resolveSession($callEvent);

        if (! $session instanceof WhatsAppCallSession) {
            $result = [
                'result' => 'ignored_unmatched',
                'wa_call_id' => $callId,
                'customer_wa_id' => $customerWaId,
                'local_status' => $localStatus,
                'termination_reason' => $terminationReason,
                'debug' => $debugPayload,
            ];

            $this->auditService->warning('webhook_call_unmatched', [
                'wa_call_id' => $callId,
                'result' => 'ignored_unmatched',
                'status_after' => $localStatus,
            ]);

            WaLog::warning('[CallWebhook] Call event ignored because no matching session was found', $result);

            return $result;
        }

        $storedWebhookEntry = $this->buildStoredWebhookEntry(
            callEvent: $callEvent,
            localStatus: $localStatus,
            context: $context,
        );

        $metaPayload = $this->mergeWebhookMetaPayload($session, $storedWebhookEntry, $context);
        $isDuplicateEvent = $this->hasWebhookEventFingerprint($session, (string) ($storedWebhookEntry['event_hash'] ?? ''));
        $waCallIdWillChange = $callId !== null && $callId !== (string) ($session->wa_call_id ?? '');
        $permissionStatus = $this->resolvePermissionStatus($localStatus, $terminationReason);
        $permissionWillChange = $permissionStatus !== null && $permissionStatus !== (string) ($session->permission_status ?? '');

        if (
            (string) $session->status === $localStatus
            && $isDuplicateEvent
            && ! $waCallIdWillChange
            && ! $permissionWillChange
        ) {
            $result = [
                'result' => 'noop_already_synced',
                'session_id' => (int) $session->id,
                'conversation_id' => (int) $session->conversation_id,
                'wa_call_id' => $callId ?: $session->wa_call_id,
                'customer_wa_id' => $customerWaId,
                'local_status' => $localStatus,
                'termination_reason' => $terminationReason,
                'debug' => $debugPayload,
            ];

            $this->auditService->info('webhook_call_ignored_duplicate', [
                'conversation_id' => (int) $session->conversation_id,
                'call_session_id' => (int) $session->id,
                'wa_call_id' => $callId ?: $session->wa_call_id,
                'result' => 'noop_already_synced',
                'status_before' => $session->status,
                'status_after' => $localStatus,
            ]);

            WaLog::info('[CallWebhook] Call event already synced', $result);

            return $result;
        }

        $extra = [
            'meta_payload' => $metaPayload,
        ];

        if ($callId !== null && $waCallIdWillChange) {
            $extra['wa_call_id'] = $callId;
        }

        if ($permissionStatus !== null) {
            $extra['permission_status'] = $permissionStatus;
        }

        $timestamp = $this->extractTimestamp($callEvent);

        if ($timestamp !== null && $session->started_at === null && ! $session->isFinished()) {
            $extra['started_at'] = $timestamp;
        }

        if ($timestamp !== null && $localStatus === WhatsAppCallSession::STATUS_CONNECTED && $session->answered_at === null) {
            $extra['answered_at'] = $timestamp;
        }

        if ($timestamp !== null && in_array($localStatus, WhatsAppCallSession::FINISHED_STATUSES, true) && $session->ended_at === null) {
            $extra['ended_at'] = $timestamp;
        }

        $updatedSession = match ($localStatus) {
            WhatsAppCallSession::STATUS_PERMISSION_REQUESTED => $this->callSessionService->markPermissionRequested($session, $extra),
            WhatsAppCallSession::STATUS_RINGING => $this->callSessionService->markRinging($session, $extra),
            WhatsAppCallSession::STATUS_CONNECTED => $this->callSessionService->markConnected($session, $extra),
            WhatsAppCallSession::STATUS_REJECTED => $this->callSessionService->markRejected($session, $terminationReason, $extra),
            WhatsAppCallSession::STATUS_MISSED => $this->callSessionService->markMissed($session, $terminationReason, $extra),
            WhatsAppCallSession::STATUS_FAILED => $this->callSessionService->markFailed($session, $terminationReason, $extra),
            WhatsAppCallSession::STATUS_ENDED => $this->callSessionService->markEnded($session, $terminationReason, $extra),
            default => $this->callSessionService->updateStatus($session, $localStatus, $extra),
        };

        $result = [
            'result' => 'processed',
            'session_id' => (int) $updatedSession->id,
            'conversation_id' => (int) $updatedSession->conversation_id,
            'wa_call_id' => $updatedSession->wa_call_id,
            'customer_wa_id' => $customerWaId,
            'local_status' => $localStatus,
            'termination_reason' => $terminationReason,
            'debug' => $debugPayload,
        ];

        $this->auditService->info('webhook_call_status_applied', [
            'conversation_id' => (int) $updatedSession->conversation_id,
            'call_session_id' => (int) $updatedSession->id,
            'wa_call_id' => $updatedSession->wa_call_id,
            'result' => 'processed',
            'status_before' => $session->status,
            'status_after' => $updatedSession->status,
            'permission_status' => $updatedSession->permission_status,
        ]);

        WaLog::info('[CallWebhook] Call event processed', $result);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $callEvent
     */
    public function resolveSession(array $callEvent): ?WhatsAppCallSession
    {
        $callId = $this->extractCallId($callEvent);

        if ($callId !== null) {
            $session = WhatsAppCallSession::query()
                ->where('wa_call_id', $callId)
                ->latest('id')
                ->first();

            if ($session instanceof WhatsAppCallSession) {
                return $session;
            }
        }

        $customerWaId = $this->extractCustomerWaId($callEvent);
        $customerPhone = $customerWaId !== null ? $this->phoneService->toE164($customerWaId) : '';

        if ($customerPhone === '' || ! $this->phoneService->isValidE164($customerPhone)) {
            return null;
        }

        $candidates = WhatsAppCallSession::query()
            ->where('channel', 'whatsapp')
            ->whereHas('customer', fn ($query) => $query->where('phone_e164', $customerPhone))
            ->latest('id')
            ->limit(10)
            ->get();

        if ($candidates->isEmpty()) {
            return null;
        }

        return $candidates->first(fn (WhatsAppCallSession $session): bool => $session->isActive())
            ?? $candidates->first();
    }

    /**
     * @param  array<string, mixed>  $callEvent
     */
    public function mapMetaCallEventToLocalStatus(array $callEvent): ?string
    {
        $event = $this->normalizePhrase($this->extractMetaEventName($callEvent));
        $terminationReason = $this->normalizePhrase($this->extractTerminationReason($callEvent));
        $combined = trim($event.' '.$terminationReason);

        if ($combined === '') {
            return null;
        }

        if ($this->containsAny($combined, ['timeout', 'timed out', 'no answer', 'not answered', 'missed', 'unanswered', 'expired'])) {
            return WhatsAppCallSession::STATUS_MISSED;
        }

        if ($this->containsAny($combined, ['reject', 'rejected', 'decline', 'declined'])) {
            return WhatsAppCallSession::STATUS_REJECTED;
        }

        if ($this->containsAny($combined, ['failed', 'failure', 'error', 'transport error', 'setup failed', 'client setup failed'])) {
            return WhatsAppCallSession::STATUS_FAILED;
        }

        if ($this->containsAny($combined, ['permission requested', 'request permission', 'permission request', 'permission pending', 'consent requested'])) {
            return WhatsAppCallSession::STATUS_PERMISSION_REQUESTED;
        }

        if ($this->containsAny($combined, ['accepted', 'answered', 'answer', 'established', 'connected', 'connect', 'active'])) {
            return WhatsAppCallSession::STATUS_CONNECTED;
        }

        if ($this->containsAny($combined, ['connecting'])) {
            return WhatsAppCallSession::STATUS_CONNECTING;
        }

        if ($this->containsAny($combined, ['ringing', 'calling', 'dialing', 'dialling', 'initiated', 'initiating', 'offered'])) {
            return WhatsAppCallSession::STATUS_RINGING;
        }

        if ($this->containsAny($combined, ['terminated', 'terminate', 'completed', 'complete', 'hangup', 'hung up', 'ended', 'end', 'disconnect'])) {
            return WhatsAppCallSession::STATUS_ENDED;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $callEvent
     */
    public function extractCallId(array $callEvent): ?string
    {
        foreach ([
            $callEvent['wa_call_id'] ?? null,
            $callEvent['call_id'] ?? null,
            data_get($callEvent, 'raw.wa_call_id'),
            data_get($callEvent, 'raw.call_id'),
            data_get($callEvent, 'raw.id'),
            data_get($callEvent, 'raw.call.id'),
            data_get($callEvent, 'raw.call.call_id'),
            data_get($callEvent, 'raw.data.call_id'),
            data_get($callEvent, 'raw.data.id'),
        ] as $candidate) {
            $normalized = $this->normalizeString($candidate);

            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $callEvent
     */
    public function extractCustomerWaId(array $callEvent): ?string
    {
        $explicit = $this->normalizeString($callEvent['customer_wa_id'] ?? null);
        if ($explicit !== null) {
            return $explicit;
        }

        $from = $this->normalizeString($callEvent['from'] ?? null);
        $to = $this->normalizeString($callEvent['to'] ?? null);
        $direction = $this->normalizePhrase($callEvent['direction'] ?? null);

        $businessPhones = array_filter([
            $this->normalizeString(data_get($callEvent, 'metadata.display_phone_number')),
            $this->normalizeString(data_get($callEvent, 'metadata.phone_number')),
            $this->normalizeString(data_get($callEvent, 'raw.metadata.display_phone_number')),
            $this->normalizeString(data_get($callEvent, 'raw.metadata.phone_number')),
            $this->normalizeString(data_get($callEvent, 'raw.business_phone')),
        ]);

        if ($direction !== '') {
            if ($this->containsAny($direction, ['business initiated', 'outbound', 'business to user'])) {
                return $to ?? $from;
            }

            if ($this->containsAny($direction, ['user initiated', 'inbound', 'user to business', 'customer initiated'])) {
                return $from ?? $to;
            }
        }

        if ($from !== null && ! $this->matchesBusinessPhone($from, $businessPhones)) {
            return $from;
        }

        if ($to !== null && ! $this->matchesBusinessPhone($to, $businessPhones)) {
            return $to;
        }

        return $from ?? $to;
    }

    /**
     * @param  array<string, mixed>  $callEvent
     */
    public function extractTerminationReason(array $callEvent): ?string
    {
        foreach ([
            $callEvent['termination_reason'] ?? null,
            $callEvent['reason'] ?? null,
            data_get($callEvent, 'raw.termination_reason'),
            data_get($callEvent, 'raw.reason'),
            data_get($callEvent, 'raw.terminate.reason'),
            data_get($callEvent, 'raw.end_reason'),
            data_get($callEvent, 'raw.error.message'),
            data_get($callEvent, 'raw.errors.0.message'),
            data_get($callEvent, 'raw.errors.0.code'),
        ] as $candidate) {
            $normalized = $this->normalizeString($candidate);

            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $callEvent
     * @return array<string, mixed>
     */
    public function buildDebugPayload(array $callEvent): array
    {
        return [
            'wa_call_id' => $this->extractCallId($callEvent),
            'event' => $this->extractMetaEventName($callEvent),
            'direction' => $callEvent['direction'] ?? null,
            'from' => $callEvent['from'] ?? null,
            'to' => $callEvent['to'] ?? null,
            'customer_wa_id' => $this->extractCustomerWaId($callEvent),
            'timestamp' => $callEvent['timestamp'] ?? null,
            'termination_reason' => $this->extractTerminationReason($callEvent),
            'metadata' => [
                'phone_number_id' => data_get($callEvent, 'metadata.phone_number_id'),
                'display_phone_number' => data_get($callEvent, 'metadata.display_phone_number'),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $callEvent
     */
    private function extractMetaEventName(array $callEvent): ?string
    {
        foreach ([
            $callEvent['event'] ?? null,
            $callEvent['status'] ?? null,
            $callEvent['call_status'] ?? null,
            $callEvent['state'] ?? null,
            data_get($callEvent, 'raw.event'),
            data_get($callEvent, 'raw.status'),
            data_get($callEvent, 'raw.call_status'),
            data_get($callEvent, 'raw.state'),
            data_get($callEvent, 'raw.call.event'),
            data_get($callEvent, 'raw.call.status'),
            data_get($callEvent, 'raw.data.event'),
            data_get($callEvent, 'raw.data.status'),
        ] as $candidate) {
            $normalized = $this->normalizeString($candidate);

            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $callEvent
     */
    private function extractTimestamp(array $callEvent): ?string
    {
        $timestamp = $callEvent['timestamp'] ?? data_get($callEvent, 'raw.timestamp');
        $normalized = $this->normalizeString($timestamp);

        return $normalized !== null ? $normalized : null;
    }

    /**
     * @param  array<string, mixed>  $callEvent
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function buildStoredWebhookEntry(array $callEvent, string $localStatus, array $context = []): array
    {
        $payload = [
            'wa_call_id' => $this->extractCallId($callEvent),
            'event' => $this->extractMetaEventName($callEvent),
            'local_status' => $localStatus,
            'direction' => $callEvent['direction'] ?? null,
            'from' => $callEvent['from'] ?? null,
            'to' => $callEvent['to'] ?? null,
            'customer_wa_id' => $this->extractCustomerWaId($callEvent),
            'timestamp' => $this->extractTimestamp($callEvent),
            'termination_reason' => $this->extractTerminationReason($callEvent),
        ];

        $payload['event_hash'] = sha1((string) json_encode($payload));
        $payload['received_at'] = now()->toIso8601String();
        $payload['trace_id'] = (string) ($context['trace_id'] ?? WaLog::traceId());
        $payload['raw'] = $this->truncateStoredPayload($callEvent['raw'] ?? []);

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $webhookEntry
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function mergeWebhookMetaPayload(
        WhatsAppCallSession $session,
        array $webhookEntry,
        array $context = [],
    ): array {
        $metaPayload = is_array($session->meta_payload) ? $session->meta_payload : [];
        $webhookCalls = is_array($metaPayload['webhook_calls'] ?? null)
            ? array_values(array_filter($metaPayload['webhook_calls'], 'is_array'))
            : [];

        if (! $this->hasEventFingerprintInHistory($webhookCalls, (string) ($webhookEntry['event_hash'] ?? ''))) {
            $webhookCalls[] = $webhookEntry;
        }

        $metaPayload['webhook_calls'] = array_slice($webhookCalls, -self::MAX_STORED_WEBHOOK_EVENTS);
        $metaPayload['last_webhook_call'] = Arr::except($webhookEntry, ['raw']);
        $metaPayload['last_webhook_sync_at'] = now()->toIso8601String();
        $metaPayload['webhook_trace_id'] = (string) ($context['trace_id'] ?? WaLog::traceId());

        return $metaPayload;
    }

    private function resolvePermissionStatus(string $localStatus, ?string $terminationReason = null): ?string
    {
        return match ($localStatus) {
            WhatsAppCallSession::STATUS_PERMISSION_REQUESTED => WhatsAppCallSession::PERMISSION_REQUESTED,
            WhatsAppCallSession::STATUS_CONNECTED => WhatsAppCallSession::PERMISSION_GRANTED,
            WhatsAppCallSession::STATUS_REJECTED => $this->containsAny($this->normalizePhrase($terminationReason), ['permission', 'consent', 'deny', 'denied'])
                ? WhatsAppCallSession::PERMISSION_DENIED
                : null,
            default => null,
        };
    }

    private function hasWebhookEventFingerprint(WhatsAppCallSession $session, string $eventHash): bool
    {
        $history = is_array(data_get($session->meta_payload, 'webhook_calls'))
            ? data_get($session->meta_payload, 'webhook_calls')
            : [];

        return $this->hasEventFingerprintInHistory(is_array($history) ? $history : [], $eventHash);
    }

    /**
     * @param  array<int, mixed>  $history
     */
    private function hasEventFingerprintInHistory(array $history, string $eventHash): bool
    {
        if ($eventHash === '') {
            return false;
        }

        foreach ($history as $event) {
            if (! is_array($event)) {
                continue;
            }

            if ((string) ($event['event_hash'] ?? '') === $eventHash) {
                return true;
            }
        }

        return false;
    }

    private function matchesBusinessPhone(string $candidate, array $businessPhones): bool
    {
        $candidateDigits = $this->phoneService->toDigits($candidate);

        foreach ($businessPhones as $businessPhone) {
            if (! is_string($businessPhone) || $businessPhone === '') {
                continue;
            }

            if ($candidateDigits !== '' && $candidateDigits === $this->phoneService->toDigits($businessPhone)) {
                return true;
            }
        }

        return false;
    }

    private function containsAny(string $haystack, array $needles): bool
    {
        if ($haystack === '') {
            return false;
        }

        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function normalizePhrase(mixed $value): string
    {
        $string = $this->normalizeString($value);

        if ($string === null) {
            return '';
        }

        return trim(str_replace(['-', '_'], ' ', strtolower($string)));
    }

    private function normalizeString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_scalar($value)) {
            $normalized = trim((string) $value);

            return $normalized !== '' ? $normalized : null;
        }

        return null;
    }

    private function truncateStoredPayload(mixed $value, int $depth = 0): mixed
    {
        if ($depth >= 4) {
            return '[truncated-depth]';
        }

        if (is_array($value)) {
            $limited = [];
            $count = 0;

            foreach ($value as $key => $item) {
                $limited[$key] = $this->truncateStoredPayload($item, $depth + 1);
                $count++;

                if ($count >= 25) {
                    $limited['_truncated'] = true;
                    break;
                }
            }

            return $limited;
        }

        if (is_string($value) && mb_strlen($value) > 500) {
            return mb_substr($value, 0, 500).'...[truncated]';
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $callEvent
     */
    private function buildDedupKey(array $callEvent, ?string $localStatus): string
    {
        return sha1(implode('|', [
            $this->extractCallId($callEvent) ?? '',
            $this->extractMetaEventName($callEvent) ?? '',
            $localStatus ?? '',
            $this->extractTimestamp($callEvent) ?? '',
            $this->extractCustomerWaId($callEvent) ?? '',
            $this->extractTerminationReason($callEvent) ?? '',
        ]));
    }
}
