<?php

namespace App\Support\Transformers;

use App\Models\Conversation;
use App\Models\WhatsAppCallSession;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;

class CallTimelineTransformer
{
    private const DEFAULT_SESSION_LIMIT = 6;

    /**
     * @return list<array<string, mixed>>
     */
    public function forConversation(Conversation $conversation, int $limitSessions = self::DEFAULT_SESSION_LIMIT): array
    {
        if (! WhatsAppCallSession::isTableAvailable()) {
            return [];
        }

        $conversationId = $conversation->getKey();

        if (! is_numeric($conversationId)) {
            return [];
        }

        $sessions = WhatsAppCallSession::query()
            ->where('conversation_id', (int) $conversationId)
            ->latest('id')
            ->limit(max(1, $limitSessions))
            ->get()
            ->sortBy(fn (WhatsAppCallSession $session) => [
                $session->created_at?->getTimestamp() ?? 0,
                (int) $session->id,
            ])
            ->values();

        $events = [];

        foreach ($sessions as $session) {
            foreach ($this->transformSession($session) as $event) {
                $this->pushUniqueEvent($events, $event);
            }
        }

        usort($events, function (array $left, array $right): int {
            $leftSort = (string) ($left['__sort_at'] ?? '');
            $rightSort = (string) ($right['__sort_at'] ?? '');

            if ($leftSort !== $rightSort) {
                return $leftSort <=> $rightSort;
            }

            return ((int) ($left['call_session_id'] ?? 0)) <=> ((int) ($right['call_session_id'] ?? 0));
        });

        return array_values(array_map(function (array $event): array {
            unset($event['__sort_at'], $event['__fingerprint']);

            return $event;
        }, $events));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function transformSession(WhatsAppCallSession $session): array
    {
        $events = [];

        $permissionRequestedAt = $session->permissionRequestedAt();
        if (
            $permissionRequestedAt !== null
            || (string) $session->permission_status === WhatsAppCallSession::PERMISSION_REQUESTED
            || (string) $session->status === WhatsAppCallSession::STATUS_PERMISSION_REQUESTED
        ) {
            $this->pushUniqueEvent($events, $this->buildEvent(
                session: $session,
                event: 'permission_requested',
                label: $this->labelForEvent('permission_requested'),
                timestamp: $permissionRequestedAt
                    ?? $this->resolveTimestamp($session->safeMetaPayload(), [
                        'permission.requested_at',
                        'permission.last_requested_at',
                    ])
                    ?? $session->created_at,
                status: $session->status ?: WhatsAppCallSession::STATUS_PERMISSION_REQUESTED,
            ));
        }

        $permissionGrantedAt = $this->resolveTimestamp($session->safeMetaPayload(), [
            'permission.granted_at',
        ]);
        if (
            $permissionGrantedAt !== null
            && (string) $session->permission_status === WhatsAppCallSession::PERMISSION_GRANTED
        ) {
            $this->pushUniqueEvent($events, $this->buildEvent(
                session: $session,
                event: 'permission_granted',
                label: $this->labelForEvent('permission_granted'),
                timestamp: $permissionGrantedAt,
                status: $session->status ?: WhatsAppCallSession::STATUS_INITIATED,
            ));
        }

        foreach ($this->buildStoredHistoryEvents($session) as $historyEvent) {
            $this->pushUniqueEvent($events, $historyEvent);
        }

        if ($this->shouldIncludeCallStartedEvent($session)) {
            $this->pushUniqueEvent($events, $this->buildEvent(
                session: $session,
                event: 'call_started',
                label: $this->labelForEvent('call_started'),
                timestamp: $session->started_at
                    ?? $this->resolveTimestamp($session->safeMetaPayload(), [
                        'outbound_call.last_started_at',
                        'calling_api.last_action_at',
                    ])
                    ?? $session->created_at,
                status: $session->status ?: WhatsAppCallSession::STATUS_INITIATED,
            ));
        }

        if ($session->isRinging() || (string) $session->status === WhatsAppCallSession::STATUS_CONNECTING) {
            $this->pushUniqueEvent($events, $this->buildEvent(
                session: $session,
                event: (string) $session->status === WhatsAppCallSession::STATUS_CONNECTING ? 'connecting' : 'ringing',
                label: $this->labelForEvent((string) $session->status === WhatsAppCallSession::STATUS_CONNECTING ? 'connecting' : 'ringing'),
                timestamp: $this->resolveTimestamp($session->safeMetaPayload(), [
                    'last_webhook_call.timestamp',
                    'last_webhook_sync_at',
                ]) ?? $session->updated_at ?? $session->started_at ?? $session->created_at,
                status: $session->status ?: WhatsAppCallSession::STATUS_RINGING,
            ));
        }

        if ($session->isConnected()) {
            $this->pushUniqueEvent($events, $this->buildEvent(
                session: $session,
                event: 'connected',
                label: $this->labelForEvent('connected'),
                timestamp: $session->answered_at ?? $session->updated_at ?? $session->started_at,
                status: $session->status,
            ));
        }

        if ($session->isFinished()) {
            $finalEvent = $this->finalEventForSession($session);

            $this->pushUniqueEvent($events, $this->buildEvent(
                session: $session,
                event: $finalEvent,
                label: $this->labelForEvent($finalEvent, $session->end_reason),
                timestamp: $session->ended_at ?? $session->updated_at ?? $session->created_at,
                status: $session->status,
                endReason: $session->end_reason,
            ));
        }

        return $events;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildStoredHistoryEvents(WhatsAppCallSession $session): array
    {
        $history = data_get($session->safeMetaPayload(), 'webhook_calls');
        if (! is_array($history)) {
            return [];
        }

        $events = [];

        foreach ($history as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $status = $this->normalizeToken(
                $entry['local_status'] ?? $entry['event'] ?? null,
            );
            $event = $this->timelineEventForWebhookEntry($status, $entry['termination_reason'] ?? null);

            if ($event === null) {
                continue;
            }

            $timestamp = $this->resolveTimestamp($entry, ['timestamp', 'received_at']) ?? $session->updated_at ?? $session->created_at;
            if ($timestamp === null) {
                continue;
            }

            $events[] = $this->buildEvent(
                session: $session,
                event: $event,
                label: $this->labelForEvent($event, $entry['termination_reason'] ?? null),
                timestamp: $timestamp,
                status: $status ?? $session->status,
                endReason: $entry['termination_reason'] ?? null,
            );
        }

        return $events;
    }

    private function shouldIncludeCallStartedEvent(WhatsAppCallSession $session): bool
    {
        $status = (string) $session->status;

        if ($status === WhatsAppCallSession::STATUS_PERMISSION_REQUESTED) {
            return false;
        }

        if ($session->wa_call_id !== null && trim((string) $session->wa_call_id) !== '') {
            return true;
        }

        return in_array($status, [
            WhatsAppCallSession::STATUS_INITIATED,
            WhatsAppCallSession::STATUS_RINGING,
            WhatsAppCallSession::STATUS_CONNECTING,
            WhatsAppCallSession::STATUS_CONNECTED,
            WhatsAppCallSession::STATUS_REJECTED,
            WhatsAppCallSession::STATUS_MISSED,
            WhatsAppCallSession::STATUS_ENDED,
            WhatsAppCallSession::STATUS_FAILED,
        ], true);
    }

    private function finalEventForSession(WhatsAppCallSession $session): string
    {
        return match ((string) $session->status) {
            WhatsAppCallSession::STATUS_REJECTED => 'rejected',
            WhatsAppCallSession::STATUS_MISSED => 'missed',
            WhatsAppCallSession::STATUS_FAILED => 'failed',
            default => 'ended',
        };
    }

    private function timelineEventForWebhookEntry(?string $status, mixed $terminationReason = null): ?string
    {
        return match ($status) {
            WhatsAppCallSession::STATUS_RINGING => 'ringing',
            WhatsAppCallSession::STATUS_CONNECTING => 'connecting',
            WhatsAppCallSession::STATUS_CONNECTED => 'connected',
            WhatsAppCallSession::STATUS_REJECTED => 'rejected',
            WhatsAppCallSession::STATUS_MISSED => 'missed',
            WhatsAppCallSession::STATUS_FAILED => 'failed',
            WhatsAppCallSession::STATUS_ENDED => $this->finalEventFromReason($terminationReason),
            WhatsAppCallSession::STATUS_PERMISSION_REQUESTED => 'permission_requested',
            default => null,
        };
    }

    private function finalEventFromReason(mixed $endReason = null): string
    {
        $normalizedReason = $this->normalizeToken($endReason);

        if ($normalizedReason !== null && in_array($normalizedReason, ['rejected', 'declined', 'denied', 'busy'], true)) {
            return 'rejected';
        }

        if ($normalizedReason !== null && in_array($normalizedReason, ['missed', 'no_answer', 'not_answered', 'timeout', 'unanswered'], true)) {
            return 'missed';
        }

        if ($normalizedReason !== null && in_array($normalizedReason, ['failed', 'error'], true)) {
            return 'failed';
        }

        return 'ended';
    }

    /**
     * @param  array<string, mixed>  $events
     */
    private function pushUniqueEvent(array &$events, array $event): void
    {
        $fingerprint = (string) ($event['__fingerprint'] ?? '');
        if ($fingerprint === '') {
            return;
        }

        foreach ($events as $existing) {
            if ((string) ($existing['__fingerprint'] ?? '') === $fingerprint) {
                return;
            }
        }

        $events[] = $event;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildEvent(
        WhatsAppCallSession $session,
        string $event,
        string $label,
        CarbonInterface|string|null $timestamp,
        ?string $status = null,
        ?string $endReason = null,
    ): array {
        $timestampValue = $this->normalizeTimestamp($timestamp);
        $timestampIso = $timestampValue?->toIso8601String();
        $normalizedStatus = $this->normalizeToken($status);
        $normalizedEndReason = $this->normalizeToken($endReason);

        return [
            'type' => 'call_event',
            'event' => $event,
            'label' => $label,
            'timestamp' => $timestampIso,
            'call_session_id' => $session->id !== null ? (int) $session->id : null,
            'call_type' => $session->call_type,
            'status' => $normalizedStatus ?? (string) $session->status,
            'end_reason' => $normalizedEndReason,
            '__sort_at' => $timestampValue?->format('U.u') ?? sprintf('%d.000000', (int) ($session->id ?? 0)),
            '__fingerprint' => implode('|', array_filter([
                (string) $session->id,
                $event,
                $normalizedStatus ?? (string) $session->status,
                $timestampIso ?? 'na',
                $normalizedEndReason ?? 'na',
            ])),
        ];
    }

    private function labelForEvent(string $event, ?string $endReason = null): string
    {
        return match ($event) {
            'permission_requested' => 'Permintaan izin panggilan dikirim',
            'permission_granted' => 'Izin panggilan tersedia',
            'call_started' => 'Panggilan WhatsApp dimulai',
            'ringing' => 'Panggilan berdering',
            'connecting' => 'Panggilan sedang menyambung',
            'connected' => 'Panggilan terhubung',
            'rejected' => 'Panggilan ditolak pengguna',
            'missed' => 'Panggilan tidak dijawab',
            'failed' => 'Panggilan gagal',
            'ended' => $this->labelForEndedReason($endReason),
            default => 'Pembaruan panggilan diterima',
        };
    }

    private function labelForEndedReason(?string $endReason = null): string
    {
        return match ($this->normalizeToken($endReason)) {
            'completed', 'ended', 'hangup', 'disconnected' => 'Panggilan berakhir',
            'no_answer', 'not_answered', 'timeout', 'unanswered' => 'Panggilan tidak dijawab',
            'rejected', 'declined', 'busy', 'denied' => 'Panggilan ditolak pengguna',
            'failed', 'error' => 'Panggilan gagal',
            default => 'Panggilan berakhir',
        };
    }

    private function resolveTimestamp(array $payload, array $paths): ?CarbonInterface
    {
        foreach ($paths as $path) {
            $value = data_get($payload, $path);
            $timestamp = $this->normalizeTimestamp($value);

            if ($timestamp !== null) {
                return $timestamp;
            }
        }

        return null;
    }

    private function normalizeTimestamp(mixed $value): ?CarbonInterface
    {
        if ($value instanceof CarbonInterface) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }

        if (is_numeric($value)) {
            return Carbon::createFromTimestamp((int) $value);
        }

        if (is_string($value)) {
            $normalized = trim($value);

            if ($normalized === '') {
                return null;
            }

            try {
                return Carbon::parse($normalized);
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    private function normalizeToken(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $normalized = trim(strtolower((string) $value));
        if ($normalized === '') {
            return null;
        }

        return str_replace([' ', '-'], '_', $normalized);
    }
}
