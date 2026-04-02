<?php

namespace App\Models;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;

class WhatsAppCallSession extends Model
{
    use HasFactory;

    protected $table = 'whatsapp_call_sessions';

    public const STATUS_INITIATED = 'initiated';
    public const STATUS_PERMISSION_REQUESTED = 'permission_requested';
    public const STATUS_RINGING = 'ringing';
    public const STATUS_CONNECTING = 'connecting';
    public const STATUS_CONNECTED = 'connected';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_MISSED = 'missed';
    public const STATUS_ENDED = 'ended';
    public const STATUS_FAILED = 'failed';

    public const FINAL_STATUS_COMPLETED = 'completed';
    public const FINAL_STATUS_MISSED = 'missed';
    public const FINAL_STATUS_REJECTED = 'rejected';
    public const FINAL_STATUS_FAILED = 'failed';
    public const FINAL_STATUS_CANCELLED = 'cancelled';
    public const FINAL_STATUS_PERMISSION_PENDING = 'permission_pending';
    public const FINAL_STATUS_IN_PROGRESS = 'in_progress';

    public const PERMISSION_UNKNOWN = 'unknown';
    public const PERMISSION_REQUIRED = 'required';
    public const PERMISSION_REQUESTED = 'requested';
    public const PERMISSION_GRANTED = 'granted';
    public const PERMISSION_DENIED = 'denied';
    public const PERMISSION_EXPIRED = 'expired';
    public const PERMISSION_RATE_LIMITED = 'rate_limited';
    public const PERMISSION_FAILED = 'failed';

    public const ACTIVE_STATUSES = [
        self::STATUS_INITIATED,
        self::STATUS_PERMISSION_REQUESTED,
        self::STATUS_RINGING,
        self::STATUS_CONNECTING,
        self::STATUS_CONNECTED,
    ];

    public const RINGING_STATUSES = [
        self::STATUS_INITIATED,
        self::STATUS_PERMISSION_REQUESTED,
        self::STATUS_RINGING,
        self::STATUS_CONNECTING,
    ];

    public const FINISHED_STATUSES = [
        self::STATUS_REJECTED,
        self::STATUS_MISSED,
        self::STATUS_ENDED,
        self::STATUS_FAILED,
    ];

    protected $fillable = [
        'conversation_id',
        'customer_id',
        'initiated_by_user_id',
        'channel',
        'direction',
        'call_type',
        'status',
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
    ];

    protected $casts = [
        'meta_payload' => 'array',
        'timeline_snapshot' => 'array',
        'started_at' => 'datetime',
        'answered_at' => 'datetime',
        'connected_at' => 'datetime',
        'ended_at' => 'datetime',
        'last_permission_requested_at' => 'datetime',
        'rate_limited_until' => 'datetime',
        'last_status_at' => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function initiatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by_user_id');
    }

    public static function isTableAvailable(): bool
    {
        try {
            return Schema::hasTable((new static())->getTable());
        } catch (\Throwable) {
            return false;
        }
    }

    public function isActive(): bool
    {
        return in_array((string) $this->status, self::ACTIVE_STATUSES, true);
    }

    public function isRinging(): bool
    {
        return in_array((string) $this->status, self::RINGING_STATUSES, true);
    }

    public function isConnected(): bool
    {
        return (string) $this->status === self::STATUS_CONNECTED;
    }

    public function isFinished(): bool
    {
        return in_array((string) $this->status, self::FINISHED_STATUSES, true);
    }

    public function connectedAt(): ?CarbonInterface
    {
        return $this->connected_at ?? $this->answered_at;
    }

    public function hasGrantedPermission(): bool
    {
        if ((string) $this->permission_status !== self::PERMISSION_GRANTED) {
            return false;
        }

        $expiresAt = $this->permissionExpiresAt();

        return $expiresAt === null || $expiresAt->isFuture();
    }

    public function hasPendingPermissionRequest(): bool
    {
        if ((string) $this->permission_status !== self::PERMISSION_REQUESTED) {
            return false;
        }

        $expiresAt = $this->permissionExpiresAt();

        return $expiresAt === null || $expiresAt->isFuture();
    }

    public function requiresPermissionRequest(): bool
    {
        if ($this->hasGrantedPermission()) {
            return false;
        }

        if ($this->hasPendingPermissionRequest() || $this->isPermissionCoolingDown()) {
            return false;
        }

        return true;
    }

    public function isPermissionCoolingDown(): bool
    {
        $cooldownUntil = $this->rateLimitedUntil();

        return $cooldownUntil !== null && $cooldownUntil->isFuture();
    }

    public function canRequestPermissionNow(): bool
    {
        return ! $this->hasGrantedPermission()
            && ! $this->hasPendingPermissionRequest()
            && ! $this->isPermissionCoolingDown();
    }

    public function canStartBusinessInitiatedCall(): bool
    {
        return ! $this->isFinished()
            && ! $this->isConnected()
            && $this->hasGrantedPermission();
    }

    public function getDurationSeconds(): ?int
    {
        if ($this->duration_seconds !== null) {
            return max(0, (int) $this->duration_seconds);
        }

        $connectedAt = $this->connectedAt();
        $endedAt = $this->ended_at;

        if ($connectedAt !== null && $endedAt !== null && $endedAt->greaterThanOrEqualTo($connectedAt)) {
            return max(0, $connectedAt->diffInSeconds($endedAt));
        }

        if ($this->isFinished()) {
            return 0;
        }

        return null;
    }

    public function getDurationHuman(): ?string
    {
        $seconds = $this->getDurationSeconds();

        if ($seconds === null) {
            return null;
        }

        return self::formatDurationHuman($seconds);
    }

    public function isCompletedCall(): bool
    {
        return $this->resolvedFinalStatus() === self::FINAL_STATUS_COMPLETED;
    }

    public function isMissedCall(): bool
    {
        return $this->resolvedFinalStatus() === self::FINAL_STATUS_MISSED;
    }

    public function isRejectedCall(): bool
    {
        return $this->resolvedFinalStatus() === self::FINAL_STATUS_REJECTED;
    }

    public function resolvedFinalStatus(): string
    {
        $stored = trim((string) ($this->final_status ?? ''));

        if ($stored !== '') {
            return $stored;
        }

        if (! $this->isFinished()) {
            return $this->hasGrantedPermission()
                ? self::FINAL_STATUS_IN_PROGRESS
                : self::FINAL_STATUS_PERMISSION_PENDING;
        }

        return match ((string) $this->status) {
            self::STATUS_REJECTED => self::FINAL_STATUS_REJECTED,
            self::STATUS_MISSED => self::FINAL_STATUS_MISSED,
            self::STATUS_FAILED => self::FINAL_STATUS_FAILED,
            self::STATUS_ENDED => $this->connectedAt() !== null
                ? self::FINAL_STATUS_COMPLETED
                : self::FINAL_STATUS_CANCELLED,
            default => self::FINAL_STATUS_CANCELLED,
        };
    }

    public function finalStatusLabel(): string
    {
        return match ($this->resolvedFinalStatus()) {
            self::FINAL_STATUS_COMPLETED => 'Berhasil',
            self::FINAL_STATUS_MISSED => 'Tidak dijawab',
            self::FINAL_STATUS_REJECTED => 'Ditolak',
            self::FINAL_STATUS_FAILED => 'Gagal',
            self::FINAL_STATUS_CANCELLED => 'Dibatalkan',
            self::FINAL_STATUS_PERMISSION_PENDING => 'Menunggu izin',
            default => 'Sedang berlangsung',
        };
    }

    public function permissionExpiresAt(): ?CarbonInterface
    {
        return $this->normalizeMetaTimestamp(
            Arr::get($this->safeMetaPayload(), 'permission.expires_at')
            ?? Arr::get($this->safeMetaPayload(), 'permission.last_known_expires_at'),
        );
    }

    public function permissionRequestedAt(): ?CarbonInterface
    {
        return $this->last_permission_requested_at ?? $this->normalizeMetaTimestamp(
            Arr::get($this->safeMetaPayload(), 'permission.requested_at')
            ?? Arr::get($this->safeMetaPayload(), 'permission.last_requested_at'),
        );
    }

    public function rateLimitedUntil(): ?CarbonInterface
    {
        return $this->rate_limited_until ?? $this->normalizeMetaTimestamp(
            Arr::get($this->safeMetaPayload(), 'permission.cooldown_until')
            ?? Arr::get($this->safeMetaPayload(), 'permission.rate_limited_until'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'id' => (int) $this->id,
            'conversation_id' => $this->conversation_id !== null ? (int) $this->conversation_id : null,
            'customer_id' => $this->customer_id !== null ? (int) $this->customer_id : null,
            'channel' => (string) $this->channel,
            'direction' => (string) $this->direction,
            'call_type' => (string) $this->call_type,
            'status' => (string) $this->status,
            'wa_call_id' => $this->wa_call_id,
            'permission_status' => $this->permission_status,
            'has_granted_permission' => $this->hasGrantedPermission(),
            'requires_permission_request' => $this->requiresPermissionRequest(),
            'can_request_permission_now' => $this->canRequestPermissionNow(),
            'can_start_business_call' => $this->canStartBusinessInitiatedCall(),
            'permission_requested_at' => $this->permissionRequestedAt()?->toIso8601String(),
            'permission_expires_at' => $this->permissionExpiresAt()?->toIso8601String(),
            'last_permission_requested_at' => $this->last_permission_requested_at?->toIso8601String(),
            'rate_limited_until' => $this->rateLimitedUntil()?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'answered_at' => $this->answered_at?->toIso8601String(),
            'connected_at' => $this->connectedAt()?->toIso8601String(),
            'ended_at' => $this->ended_at?->toIso8601String(),
            'duration_seconds' => $this->getDurationSeconds(),
            'duration_human' => $this->getDurationHuman(),
            'final_status' => $this->resolvedFinalStatus(),
            'final_status_label' => $this->finalStatusLabel(),
            'end_reason' => $this->end_reason,
            'ended_by' => $this->ended_by,
            'disconnect_source' => $this->disconnect_source,
            'disconnect_reason_code' => $this->disconnect_reason_code,
            'disconnect_reason_label' => $this->disconnect_reason_label,
            'last_status_at' => $this->last_status_at?->toIso8601String(),
            'is_active' => $this->isActive(),
            'is_ringing' => $this->isRinging(),
            'is_connected' => $this->isConnected(),
            'meta_payload' => $this->safeMetaPayload(),
            'timeline_snapshot' => $this->safeTimelineSnapshot(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function safeMetaPayload(): array
    {
        return is_array($this->meta_payload) ? $this->meta_payload : [];
    }

    /**
     * @return array<int, mixed>
     */
    public function safeTimelineSnapshot(): array
    {
        return is_array($this->timeline_snapshot) ? array_values($this->timeline_snapshot) : [];
    }

    public static function formatDurationHuman(?int $seconds): ?string
    {
        if ($seconds === null) {
            return null;
        }

        $normalized = max(0, $seconds);
        if ($normalized < 60) {
            return $normalized.' dtk';
        }

        $minutes = intdiv($normalized, 60);
        $remainingSeconds = $normalized % 60;

        if ($minutes < 60) {
            return $remainingSeconds > 0
                ? sprintf('%d m %d dtk', $minutes, $remainingSeconds)
                : sprintf('%d m', $minutes);
        }

        $hours = intdiv($minutes, 60);
        $remainingMinutes = $minutes % 60;

        return $remainingMinutes > 0
            ? sprintf('%d j %d m', $hours, $remainingMinutes)
            : sprintf('%d j', $hours);
    }

    private function normalizeMetaTimestamp(mixed $value): ?CarbonInterface
    {
        if ($value === null) {
            return null;
        }

        if (is_numeric($value)) {
            return now()->setTimestamp((int) $value);
        }

        if (is_string($value) && trim($value) !== '') {
            try {
                return Carbon::parse($value);
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }
}
