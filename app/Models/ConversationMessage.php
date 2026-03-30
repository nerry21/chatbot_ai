<?php

namespace App\Models;

use App\Enums\MessageDeliveryStatus;
use App\Enums\MessageDirection;
use App\Enums\SenderType;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConversationMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'conversation_id',
        'direction',
        'sender_type',
        'message_type',
        'message_text',
        'raw_payload',
        'client_message_id',
        'channel_message_id',
        'wa_message_id',
        'ai_intent',
        'ai_confidence',
        'is_fallback',
        'sender_user_id',
        'read_at',
        'sent_at',
        'delivery_status',
        'delivery_error',
        'delivered_at',
        'delivered_to_app_at',
        'failed_at',
        'send_attempts',
        'last_send_attempt_at',
    ];

    protected $casts = [
        'direction' => MessageDirection::class,
        'sender_type' => SenderType::class,
        'delivery_status' => MessageDeliveryStatus::class,
        'raw_payload' => 'array',
        'ai_confidence' => 'decimal:4',
        'is_fallback' => 'boolean',
        'read_at' => 'datetime',
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'delivered_to_app_at' => 'datetime',
        'failed_at' => 'datetime',
        'last_send_attempt_at' => 'datetime',
        'send_attempts' => 'integer',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function senderUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }

    public function bookingIntents(): HasMany
    {
        return $this->hasMany(BookingIntent::class, 'conversation_message_id');
    }

    public function scopeInbound(Builder $query): Builder
    {
        return $query->where('direction', MessageDirection::Inbound);
    }

    public function scopeOutbound(Builder $query): Builder
    {
        return $query->where('direction', MessageDirection::Outbound);
    }

    public function scopeTextOnly(Builder $query): Builder
    {
        return $query->where('message_type', 'text');
    }

    public function scopeWithDeliveryStatus(Builder $query, MessageDeliveryStatus $status): Builder
    {
        return $query->where('delivery_status', $status);
    }

    public function isInbound(): bool
    {
        return $this->direction === MessageDirection::Inbound;
    }

    public function isOutbound(): bool
    {
        return $this->direction === MessageDirection::Outbound;
    }

    public function isReadByCustomer(): bool
    {
        return $this->read_at !== null;
    }

    public function isDeliveredToApp(): bool
    {
        return $this->delivered_to_app_at !== null;
    }

    public function markPending(): void
    {
        $this->update([
            'delivery_status' => MessageDeliveryStatus::Pending,
            'delivery_error' => null,
            'failed_at' => null,
        ]);
    }

    /**
     * @param array<string, mixed> $providerPayload
     */
    public function markSent(?string $waMessageId = null, array $providerPayload = []): void
    {
        $rawPayload = is_array($this->raw_payload) ? $this->raw_payload : [];

        if ($providerPayload !== []) {
            $rawPayload = array_merge($rawPayload, $providerPayload);
        }

        $updates = [
            'delivery_status' => MessageDeliveryStatus::Sent,
            'delivery_error' => null,
            'sent_at' => $this->sent_at ?? now(),
            'raw_payload' => $rawPayload,
            'failed_at' => null,
        ];

        if ($waMessageId !== null) {
            $updates['wa_message_id'] = $waMessageId;
            $updates['channel_message_id'] = $waMessageId;
        }

        $this->update($updates);
    }

    /**
     * @param array<string, mixed> $providerPayload
     */
    public function markDelivered(?CarbonInterface $deliveredAt = null, array $providerPayload = []): void
    {
        $rawPayload = is_array($this->raw_payload) ? $this->raw_payload : [];

        if ($providerPayload !== []) {
            $rawPayload = array_merge($rawPayload, $providerPayload);
        }

        $this->update([
            'delivery_status' => MessageDeliveryStatus::Delivered,
            'delivery_error' => null,
            'delivered_at' => $deliveredAt ?? now(),
            'sent_at' => $this->sent_at ?? now(),
            'raw_payload' => $rawPayload,
            'failed_at' => null,
        ]);
    }

    /**
     * @param array<string, mixed> $providerPayload
     */
    public function markFailed(?string $error = null, array $providerPayload = []): void
    {
        $rawPayload = is_array($this->raw_payload) ? $this->raw_payload : [];

        if ($providerPayload !== []) {
            $rawPayload = array_merge($rawPayload, $providerPayload);
        }

        $this->update([
            'delivery_status' => MessageDeliveryStatus::Failed,
            'delivery_error' => $error,
            'failed_at' => now(),
            'raw_payload' => $rawPayload,
        ]);
    }

    /**
     * @param array<string, mixed> $providerPayload
     */
    public function markSkipped(?string $reason = null, array $providerPayload = []): void
    {
        $rawPayload = is_array($this->raw_payload) ? $this->raw_payload : [];

        if ($providerPayload !== []) {
            $rawPayload = array_merge($rawPayload, $providerPayload);
        }

        $this->update([
            'delivery_status' => MessageDeliveryStatus::Skipped,
            'delivery_error' => $reason,
            'raw_payload' => $rawPayload,
            'failed_at' => null,
        ]);
    }

    public function markRead(?CarbonInterface $readAt = null): void
    {
        $timestamp = $readAt ?? now();

        $this->update([
            'read_at' => $timestamp,
            'delivery_status' => $this->delivery_status === MessageDeliveryStatus::Failed
                ? MessageDeliveryStatus::Failed
                : MessageDeliveryStatus::Delivered,
            'delivered_at' => $this->delivered_at ?? $timestamp,
            'sent_at' => $this->sent_at ?? $timestamp,
            'delivery_error' => $this->delivery_status === MessageDeliveryStatus::Failed
                ? $this->delivery_error
                : null,
        ]);
    }

    public function markDeliveredToApp(?CarbonInterface $deliveredAt = null): void
    {
        $this->update([
            'delivered_to_app_at' => $deliveredAt ?? now(),
        ]);
    }

    public function incrementSendAttempt(): void
    {
        $this->increment('send_attempts');
        $this->update(['last_send_attempt_at' => now()]);
    }

    public function isResendable(): bool
    {
        if ($this->direction !== MessageDirection::Outbound) {
            return false;
        }

        if (! in_array($this->sender_type, [SenderType::Bot, SenderType::Admin, SenderType::Agent], true)) {
            return false;
        }

        if ($this->delivery_status === MessageDeliveryStatus::Sent
            || $this->delivery_status === MessageDeliveryStatus::Delivered) {
            return false;
        }

        if ($this->delivery_status === MessageDeliveryStatus::Failed) {
            return true;
        }

        if ($this->delivery_status === MessageDeliveryStatus::Skipped
            && in_array($this->delivery_error, ['sender_disabled', 'no_valid_customer_phone'], true)) {
            return true;
        }

        return false;
    }

    public function textPreview(int $maxLength = 100): string
    {
        $text = $this->message_text ?? '';

        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        return mb_substr($text, 0, $maxLength) . '...';
    }

    public function tagWithAiResult(string $intent, float $confidence): bool
    {
        $this->ai_intent = $intent;
        $this->ai_confidence = $confidence;

        return $this->save();
    }
}
