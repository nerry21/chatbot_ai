<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsAppCallSession;
use DomainException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class WhatsAppCallSessionService
{
    private const SUPPORTED_CALL_TYPES = ['audio', 'video'];

    private const SUPPORTED_DIRECTIONS = ['business_initiated', 'user_initiated'];

    private const SUPPORTED_STATUSES = [
        WhatsAppCallSession::STATUS_INITIATED,
        WhatsAppCallSession::STATUS_PERMISSION_REQUESTED,
        WhatsAppCallSession::STATUS_RINGING,
        WhatsAppCallSession::STATUS_CONNECTING,
        WhatsAppCallSession::STATUS_CONNECTED,
        WhatsAppCallSession::STATUS_REJECTED,
        WhatsAppCallSession::STATUS_MISSED,
        WhatsAppCallSession::STATUS_ENDED,
        WhatsAppCallSession::STATUS_FAILED,
    ];

    /**
     * @param  Model|null  $conversation
     */
    public function getActiveSessionForConversation($conversation): ?WhatsAppCallSession
    {
        $conversationId = $this->resolveModelKey($conversation);

        if ($conversationId === null) {
            return null;
        }

        return WhatsAppCallSession::query()
            ->where('conversation_id', $conversationId)
            ->whereIn('status', WhatsAppCallSession::ACTIVE_STATUSES)
            ->latest('id')
            ->first();
    }

    /**
     * @param  Model|null  $conversation
     */
    public function getLatestSessionForConversation($conversation): ?WhatsAppCallSession
    {
        $conversationId = $this->resolveModelKey($conversation);

        if ($conversationId === null) {
            return null;
        }

        return WhatsAppCallSession::query()
            ->where('conversation_id', $conversationId)
            ->latest('id')
            ->first();
    }

    public function startSession(
        Model $conversation,
        ?Model $customer,
        ?Authenticatable $user,
        string $callType = 'audio',
        string $direction = 'business_initiated',
    ): WhatsAppCallSession {
        $conversationId = $this->resolveModelKey($conversation);

        if ($conversationId === null) {
            throw new DomainException('Conversation panggilan tidak valid.');
        }

        $normalizedCallType = in_array($callType, self::SUPPORTED_CALL_TYPES, true) ? $callType : 'audio';
        $normalizedDirection = in_array($direction, self::SUPPORTED_DIRECTIONS, true) ? $direction : 'business_initiated';

        return DB::transaction(function () use (
            $conversation,
            $conversationId,
            $customer,
            $user,
            $normalizedCallType,
            $normalizedDirection,
        ): WhatsAppCallSession {
            $lockedConversation = $conversation->newQuery()
                ->whereKey($conversationId)
                ->lockForUpdate()
                ->first();

            if (! $lockedConversation instanceof Model) {
                throw new DomainException('Conversation panggilan tidak ditemukan.');
            }

            $existingSession = WhatsAppCallSession::query()
                ->where('conversation_id', $conversationId)
                ->whereIn('status', WhatsAppCallSession::ACTIVE_STATUSES)
                ->lockForUpdate()
                ->latest('id')
                ->first();

            if ($existingSession instanceof WhatsAppCallSession) {
                throw new DomainException('Masih ada panggilan aktif untuk percakapan ini.');
            }

            $session = WhatsAppCallSession::query()->create([
                'conversation_id' => $conversationId,
                'customer_id' => $this->resolveModelKey($customer),
                'initiated_by_user_id' => $this->resolveAuthenticatableKey($user),
                'channel' => $this->resolveConversationChannel($lockedConversation),
                'direction' => $normalizedDirection,
                'call_type' => $normalizedCallType,
                'status' => WhatsAppCallSession::STATUS_INITIATED,
                'permission_status' => WhatsAppCallSession::PERMISSION_UNKNOWN,
                'started_at' => now(),
                'last_status_at' => now(),
                'meta_payload' => [],
                'timeline_snapshot' => [],
            ]);

            return $this->syncSessionData($session);
        });
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public function updateStatus(WhatsAppCallSession $session, string $status, array $extra = []): WhatsAppCallSession
    {
        $normalizedStatus = trim($status);

        if (! in_array($normalizedStatus, self::SUPPORTED_STATUSES, true)) {
            throw new DomainException('Status panggilan tidak didukung.');
        }

        $attributes = Arr::only($extra, [
            'channel',
            'direction',
            'call_type',
            'wa_call_id',
            'permission_status',
            'last_permission_requested_at',
            'rate_limited_until',
            'started_at',
            'answered_at',
            'connected_at',
            'ended_at',
            'duration_seconds',
            'final_status',
            'end_reason',
            'ended_by',
            'disconnect_source',
            'disconnect_reason_code',
            'disconnect_reason_label',
            'last_status_at',
            'meta_payload',
            'timeline_snapshot',
        ]);

        $metaPayload = Arr::pull($attributes, 'meta_payload', []);
        $attributes['status'] = $normalizedStatus;
        $attributes['meta_payload'] = $this->mergeMetaPayload($session, is_array($metaPayload) ? $metaPayload : []);

        $this->applyStatusTimestamps($session, $normalizedStatus, $attributes);
        $this->applyDerivedAnalytics($session, $attributes);

        if (! $this->hasMeaningfulChanges($session, $attributes)) {
            return $session->fresh() ?? $session;
        }

        $session->fill($attributes);
        $session->save();

        return $session->fresh() ?? $session;
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public function markRinging(WhatsAppCallSession $session, array $extra = []): WhatsAppCallSession
    {
        return $this->updateStatus($session, WhatsAppCallSession::STATUS_RINGING, $extra);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public function markInitiated(WhatsAppCallSession $session, array $extra = []): WhatsAppCallSession
    {
        return $this->updateStatus($session, $this->resolvePassiveStatus($session), $extra);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public function markPermissionRequested(WhatsAppCallSession $session, array $extra = []): WhatsAppCallSession
    {
        if (! array_key_exists('permission_status', $extra)) {
            $extra['permission_status'] = WhatsAppCallSession::PERMISSION_REQUESTED;
        }

        if (! array_key_exists('last_permission_requested_at', $extra)) {
            $extra['last_permission_requested_at'] = now();
        }

        return $this->updateStatus($session, $this->resolvePermissionRequestedStatus($session), $extra);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public function markPermissionRequired(WhatsAppCallSession $session, array $extra = []): WhatsAppCallSession
    {
        if (! array_key_exists('permission_status', $extra)) {
            $extra['permission_status'] = WhatsAppCallSession::PERMISSION_REQUIRED;
        }

        return $this->updateStatus($session, $this->resolvePassiveStatus($session), $extra);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public function markPermissionGranted(WhatsAppCallSession $session, array $extra = []): WhatsAppCallSession
    {
        if (! array_key_exists('permission_status', $extra)) {
            $extra['permission_status'] = WhatsAppCallSession::PERMISSION_GRANTED;
        }

        if (! array_key_exists('rate_limited_until', $extra)) {
            $extra['rate_limited_until'] = null;
        }

        return $this->updateStatus($session, $this->resolvePassiveStatus($session), $extra);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public function markPermissionDenied(WhatsAppCallSession $session, array $extra = []): WhatsAppCallSession
    {
        if (! array_key_exists('permission_status', $extra)) {
            $extra['permission_status'] = WhatsAppCallSession::PERMISSION_DENIED;
        }

        if (! array_key_exists('rate_limited_until', $extra)) {
            $extra['rate_limited_until'] = null;
        }

        return $this->updateStatus($session, $this->resolvePassiveStatus($session), $extra);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public function markPermissionExpired(WhatsAppCallSession $session, array $extra = []): WhatsAppCallSession
    {
        if (! array_key_exists('permission_status', $extra)) {
            $extra['permission_status'] = WhatsAppCallSession::PERMISSION_EXPIRED;
        }

        return $this->updateStatus($session, $this->resolvePassiveStatus($session), $extra);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public function markPermissionRateLimited(WhatsAppCallSession $session, array $extra = []): WhatsAppCallSession
    {
        if (! array_key_exists('permission_status', $extra)) {
            $extra['permission_status'] = WhatsAppCallSession::PERMISSION_RATE_LIMITED;
        }

        return $this->updateStatus($session, $this->resolvePassiveStatus($session), $extra);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public function markPermissionFailed(WhatsAppCallSession $session, array $extra = []): WhatsAppCallSession
    {
        if (! array_key_exists('permission_status', $extra)) {
            $extra['permission_status'] = WhatsAppCallSession::PERMISSION_FAILED;
        }

        return $this->updateStatus($session, $this->resolvePassiveStatus($session), $extra);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public function markConnected(WhatsAppCallSession $session, array $extra = []): WhatsAppCallSession
    {
        return $this->updateStatus($session, WhatsAppCallSession::STATUS_CONNECTED, $extra);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public function markRejected(WhatsAppCallSession $session, ?string $reason = null, array $extra = []): WhatsAppCallSession
    {
        if ($reason !== null && ! array_key_exists('end_reason', $extra)) {
            $extra['end_reason'] = $reason;
        }

        return $this->updateStatus($session, WhatsAppCallSession::STATUS_REJECTED, $extra);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public function markMissed(WhatsAppCallSession $session, ?string $reason = null, array $extra = []): WhatsAppCallSession
    {
        if ($reason !== null && ! array_key_exists('end_reason', $extra)) {
            $extra['end_reason'] = $reason;
        }

        return $this->updateStatus($session, WhatsAppCallSession::STATUS_MISSED, $extra);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public function markEnded(WhatsAppCallSession $session, ?string $reason = null, array $extra = []): WhatsAppCallSession
    {
        return $this->endSession($session, $reason, $extra);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public function endSession(WhatsAppCallSession $session, ?string $reason = null, array $extra = []): WhatsAppCallSession
    {
        if ($reason !== null && ! array_key_exists('end_reason', $extra)) {
            $extra['end_reason'] = $reason;
        }

        return $this->updateStatus($session, WhatsAppCallSession::STATUS_ENDED, $extra);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public function markFailed(WhatsAppCallSession $session, ?string $reason = null, array $extra = []): WhatsAppCallSession
    {
        return $this->failSession($session, $reason, $extra);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public function failSession(WhatsAppCallSession $session, ?string $reason = null, array $extra = []): WhatsAppCallSession
    {
        if ($reason !== null && ! array_key_exists('end_reason', $extra)) {
            $extra['end_reason'] = $reason;
        }

        return $this->updateStatus($session, WhatsAppCallSession::STATUS_FAILED, $extra);
    }

    public function buildPayload(?WhatsAppCallSession $session): ?array
    {
        return $session?->toApiArray();
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public function syncSessionData(WhatsAppCallSession $session, array $extra = []): WhatsAppCallSession
    {
        return $this->updateStatus($session, (string) $session->status, $extra);
    }

    private function resolveModelKey(?Model $model): ?int
    {
        if (! $model instanceof Model) {
            return null;
        }

        $key = $model->getKey();

        return is_numeric($key) ? (int) $key : null;
    }

    private function resolveAuthenticatableKey(?Authenticatable $user): ?int
    {
        if (! $user instanceof Authenticatable) {
            return null;
        }

        $key = $user->getAuthIdentifier();

        return is_numeric($key) ? (int) $key : null;
    }

    private function resolveConversationChannel(Model $conversation): string
    {
        $channel = trim((string) ($conversation->getAttribute('channel') ?? ''));

        return $channel !== '' ? $channel : 'whatsapp';
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function applyStatusTimestamps(WhatsAppCallSession $session, string $status, array &$attributes): void
    {
        if (
            in_array($status, WhatsAppCallSession::ACTIVE_STATUSES, true)
            && ! array_key_exists('started_at', $attributes)
            && $session->started_at === null
        ) {
            $attributes['started_at'] = now();
        }

        if (
            $status === WhatsAppCallSession::STATUS_CONNECTED
            && ! array_key_exists('answered_at', $attributes)
            && $session->answered_at === null
        ) {
            $attributes['answered_at'] = now();
        }

        if (
            $status === WhatsAppCallSession::STATUS_CONNECTED
            && ! array_key_exists('connected_at', $attributes)
            && $session->connected_at === null
        ) {
            $attributes['connected_at'] = $attributes['answered_at'] ?? now();
        }

        if (
            in_array($status, WhatsAppCallSession::FINISHED_STATUSES, true)
            && ! array_key_exists('ended_at', $attributes)
            && $session->ended_at === null
        ) {
            $attributes['ended_at'] = now();
        }
    }

    /**
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    private function mergeMetaPayload(WhatsAppCallSession $session, array $incoming): array
    {
        $current = is_array($session->meta_payload) ? $session->meta_payload : [];

        if ($incoming === []) {
            return $current;
        }

        return array_replace_recursive($current, $incoming);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function applyDerivedAnalytics(WhatsAppCallSession $session, array &$attributes): void
    {
        $snapshot = $this->buildSnapshot($session, $attributes);

        if (! array_key_exists('last_status_at', $attributes)) {
            $attributes['last_status_at'] = $snapshot->ended_at
                ?? $snapshot->connectedAt()
                ?? $snapshot->started_at
                ?? now();
        }

        if (! array_key_exists('final_status', $attributes)) {
            $attributes['final_status'] = $this->resolveFinalStatus($snapshot);
        }

        if (! array_key_exists('duration_seconds', $attributes)) {
            $attributes['duration_seconds'] = $snapshot->getDurationSeconds();
        }

        if (! array_key_exists('ended_by', $attributes)) {
            $attributes['ended_by'] = $this->resolveEndedBy($snapshot);
        }

        if (! array_key_exists('disconnect_source', $attributes)) {
            $attributes['disconnect_source'] = $this->resolveDisconnectSource($snapshot);
        }

        if (! array_key_exists('disconnect_reason_code', $attributes)) {
            $attributes['disconnect_reason_code'] = $this->resolveDisconnectReasonCode($snapshot);
        }

        if (! array_key_exists('disconnect_reason_label', $attributes)) {
            $attributes['disconnect_reason_label'] = $this->resolveDisconnectReasonLabel($snapshot);
        }

        if (! array_key_exists('timeline_snapshot', $attributes)) {
            $attributes['timeline_snapshot'] = $this->buildTimelineSnapshot($snapshot);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function buildSnapshot(WhatsAppCallSession $session, array $attributes): WhatsAppCallSession
    {
        $snapshot = $session->replicate();
        $snapshot->forceFill($attributes);

        if ($snapshot->connected_at === null && $snapshot->answered_at !== null) {
            $snapshot->connected_at = $snapshot->answered_at;
        }

        return $snapshot;
    }

    private function resolveFinalStatus(WhatsAppCallSession $session): string
    {
        $status = (string) $session->status;
        $permissionStatus = (string) ($session->permission_status ?? '');
        $endReason = $this->normalizeToken($session->end_reason);

        if (! $session->isFinished()) {
            if (! $session->hasGrantedPermission() && in_array($permissionStatus, [
                WhatsAppCallSession::PERMISSION_REQUIRED,
                WhatsAppCallSession::PERMISSION_REQUESTED,
                WhatsAppCallSession::PERMISSION_UNKNOWN,
                WhatsAppCallSession::PERMISSION_EXPIRED,
                WhatsAppCallSession::PERMISSION_RATE_LIMITED,
                WhatsAppCallSession::PERMISSION_FAILED,
            ], true)) {
                return WhatsAppCallSession::FINAL_STATUS_PERMISSION_PENDING;
            }

            return WhatsAppCallSession::FINAL_STATUS_IN_PROGRESS;
        }

        if ($status === WhatsAppCallSession::STATUS_REJECTED) {
            return WhatsAppCallSession::FINAL_STATUS_REJECTED;
        }

        if ($status === WhatsAppCallSession::STATUS_MISSED) {
            return WhatsAppCallSession::FINAL_STATUS_MISSED;
        }

        if ($status === WhatsAppCallSession::STATUS_FAILED) {
            return WhatsAppCallSession::FINAL_STATUS_FAILED;
        }

        if ($endReason !== null && in_array($endReason, [
            'rejected',
            'declined',
            'denied',
            'busy',
        ], true)) {
            return WhatsAppCallSession::FINAL_STATUS_REJECTED;
        }

        if ($endReason !== null && in_array($endReason, [
            'no_answer',
            'not_answered',
            'timeout',
            'unanswered',
            'missed',
        ], true)) {
            return WhatsAppCallSession::FINAL_STATUS_MISSED;
        }

        if ($endReason !== null && in_array($endReason, [
            'failed',
            'error',
        ], true)) {
            return WhatsAppCallSession::FINAL_STATUS_FAILED;
        }

        if ($session->connectedAt() !== null) {
            return WhatsAppCallSession::FINAL_STATUS_COMPLETED;
        }

        if (! $session->hasGrantedPermission()) {
            return WhatsAppCallSession::FINAL_STATUS_PERMISSION_PENDING;
        }

        return WhatsAppCallSession::FINAL_STATUS_CANCELLED;
    }

    private function resolveEndedBy(WhatsAppCallSession $session): ?string
    {
        if (! $session->isFinished()) {
            return null;
        }

        $reason = $this->normalizeToken($session->end_reason);
        if ($reason !== null && in_array($reason, [
            'ended_by_admin',
            'rejected_by_admin',
            'admin_cancelled',
            'cancelled_by_admin',
        ], true)) {
            return 'admin';
        }

        if ($reason !== null && in_array($reason, [
            'ended_by_customer',
            'rejected_by_customer',
            'declined_by_customer',
            'customer_hangup',
            'customer_cancelled',
        ], true)) {
            return 'customer';
        }

        return match ($this->resolveFinalStatus($session)) {
            WhatsAppCallSession::FINAL_STATUS_COMPLETED => 'customer',
            WhatsAppCallSession::FINAL_STATUS_MISSED,
            WhatsAppCallSession::FINAL_STATUS_FAILED,
            WhatsAppCallSession::FINAL_STATUS_PERMISSION_PENDING => 'system',
            default => 'unknown',
        };
    }

    private function resolveDisconnectSource(WhatsAppCallSession $session): ?string
    {
        if (! $session->isFinished()) {
            return null;
        }

        if (is_array(data_get($session->meta_payload, 'last_webhook_call'))) {
            return 'meta_webhook';
        }

        $reason = $this->normalizeToken($session->end_reason);
        if ($reason !== null && str_contains($reason, 'admin')) {
            return 'admin_action';
        }

        if ($reason !== null && str_contains($reason, 'customer')) {
            return 'customer_action';
        }

        return 'system';
    }

    private function resolveDisconnectReasonCode(WhatsAppCallSession $session): ?string
    {
        $code = data_get($session->meta_payload, 'last_webhook_call.raw.reason_code')
            ?? data_get($session->meta_payload, 'last_webhook_call.raw.reason.code')
            ?? data_get($session->meta_payload, 'last_webhook_call.raw.error.code')
            ?? data_get($session->meta_payload, 'calling_api.last_response.error.code');

        if ($code === null) {
            return $this->normalizeToken($session->end_reason);
        }

        $normalized = trim((string) $code);

        return $normalized !== '' ? $normalized : null;
    }

    private function resolveDisconnectReasonLabel(WhatsAppCallSession $session): ?string
    {
        $label = data_get($session->meta_payload, 'last_webhook_call.termination_reason')
            ?? data_get($session->meta_payload, 'last_webhook_call.raw.reason')
            ?? data_get($session->meta_payload, 'last_webhook_call.raw.reason_label')
            ?? data_get($session->meta_payload, 'calling_api.last_response.error.message')
            ?? $session->end_reason;

        $normalized = trim((string) ($label ?? ''));

        return $normalized !== '' ? $normalized : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildTimelineSnapshot(WhatsAppCallSession $session): array
    {
        $events = [];

        $push = static function (array &$items, array $entry): void {
            $fingerprint = implode('|', [
                (string) ($entry['event'] ?? ''),
                (string) ($entry['timestamp'] ?? ''),
                (string) ($entry['status'] ?? ''),
                (string) ($entry['call_session_id'] ?? ''),
            ]);

            foreach ($items as $item) {
                if (($item['_fingerprint'] ?? '') === $fingerprint) {
                    return;
                }
            }

            $entry['_fingerprint'] = $fingerprint;
            $items[] = $entry;
        };

        $permissionRequestedAt = data_get($session->meta_payload, 'permission.requested_at')
            ?? data_get($session->meta_payload, 'permission.last_requested_at');
        if ($permissionRequestedAt !== null) {
            $push($events, [
                'event' => 'permission_requested',
                'label' => 'Permintaan izin panggilan dikirim',
                'timestamp' => $this->normalizeTimestampString($permissionRequestedAt),
                'status' => (string) $session->status,
                'call_session_id' => (int) ($session->id ?? 0),
            ]);
        }

        if ($session->started_at !== null) {
            $push($events, [
                'event' => 'call_started',
                'label' => 'Panggilan WhatsApp dimulai',
                'timestamp' => $session->started_at?->toIso8601String(),
                'status' => (string) $session->status,
                'call_session_id' => (int) ($session->id ?? 0),
            ]);
        }

        $webhookCalls = is_array(data_get($session->meta_payload, 'webhook_calls'))
            ? data_get($session->meta_payload, 'webhook_calls')
            : [];

        foreach ($webhookCalls as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $event = $this->normalizeToken((string) ($entry['local_status'] ?? $entry['event'] ?? ''));
            $timestamp = $this->normalizeTimestampString($entry['timestamp'] ?? $entry['received_at'] ?? null);

            if ($event === null || $timestamp === null) {
                continue;
            }

            $push($events, [
                'event' => $event,
                'label' => trim((string) ($entry['termination_reason'] ?? $entry['event'] ?? $event)),
                'timestamp' => $timestamp,
                'status' => (string) ($entry['local_status'] ?? $session->status),
                'call_session_id' => (int) ($session->id ?? 0),
            ]);
        }

        if ($session->ended_at !== null) {
            $push($events, [
                'event' => $this->normalizeToken($this->resolveFinalStatus($session)) ?? 'ended',
                'label' => trim((string) ($session->end_reason ?? $session->finalStatusLabel())),
                'timestamp' => $session->ended_at?->toIso8601String(),
                'status' => (string) $session->status,
                'call_session_id' => (int) ($session->id ?? 0),
            ]);
        }

        usort($events, static function (array $left, array $right): int {
            return strcmp((string) ($left['timestamp'] ?? ''), (string) ($right['timestamp'] ?? ''));
        });

        return array_values(array_map(static function (array $entry): array {
            unset($entry['_fingerprint']);

            return $entry;
        }, array_slice($events, -10)));
    }

    private function normalizeToken(?string $value): ?string
    {
        $normalized = trim(strtolower((string) ($value ?? '')));

        if ($normalized === '') {
            return null;
        }

        return str_replace([' ', '-'], '_', $normalized);
    }

    private function normalizeTimestampString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof Carbon) {
            return $value->toIso8601String();
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->toIso8601String();
        }

        if (is_numeric($value)) {
            return Carbon::createFromTimestamp((int) $value)->toIso8601String();
        }

        $normalized = trim((string) $value);
        if ($normalized === '') {
            return null;
        }

        try {
            return Carbon::parse($normalized)->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function hasMeaningfulChanges(WhatsAppCallSession $session, array $attributes): bool
    {
        foreach ($attributes as $key => $value) {
            if ($this->normalizeComparableValue($session->getAttribute($key)) !== $this->normalizeComparableValue($value)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeComparableValue(mixed $value): mixed
    {
        if ($value instanceof Carbon) {
            return $value->toIso8601String();
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->toIso8601String();
        }

        if (is_array($value)) {
            $normalized = [];
            foreach ($value as $key => $item) {
                $normalized[$key] = $this->normalizeComparableValue($item);
            }
            ksort($normalized);

            return $normalized;
        }

        return $value;
    }

    private function resolvePassiveStatus(WhatsAppCallSession $session): string
    {
        $currentStatus = (string) $session->status;

        if (in_array($currentStatus, self::SUPPORTED_STATUSES, true)) {
            if (
                $session->isFinished()
                || in_array($currentStatus, [
                    WhatsAppCallSession::STATUS_RINGING,
                    WhatsAppCallSession::STATUS_CONNECTING,
                    WhatsAppCallSession::STATUS_CONNECTED,
                ], true)
            ) {
                return $currentStatus;
            }
        }

        return WhatsAppCallSession::STATUS_INITIATED;
    }

    private function resolvePermissionRequestedStatus(WhatsAppCallSession $session): string
    {
        $currentStatus = (string) $session->status;

        if (
            $session->isFinished()
            || in_array($currentStatus, [
                WhatsAppCallSession::STATUS_RINGING,
                WhatsAppCallSession::STATUS_CONNECTING,
                WhatsAppCallSession::STATUS_CONNECTED,
            ], true)
        ) {
            return $currentStatus;
        }

        return WhatsAppCallSession::STATUS_PERMISSION_REQUESTED;
    }
}
