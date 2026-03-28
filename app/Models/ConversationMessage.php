<?php

namespace App\Models;

use App\Enums\MessageDeliveryStatus;
use App\Enums\MessageDirection;
use App\Enums\SenderType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        'wa_message_id',
        'ai_intent',
        'ai_confidence',
        'is_fallback',
        'sent_at',
        // Delivery status columns (Tahap 8)
        'delivery_status',
        'delivery_error',
        'delivered_at',
        'failed_at',
        // Retry tracking columns (Tahap 9)
        'send_attempts',
        'last_send_attempt_at',
    ];

    protected $casts = [
        'direction'       => MessageDirection::class,
        'sender_type'     => SenderType::class,
        'delivery_status' => MessageDeliveryStatus::class,
        'raw_payload'     => 'array',
        'ai_confidence'   => 'decimal:4',
        'is_fallback'     => 'boolean',
        'sent_at'               => 'datetime',
        'delivered_at'          => 'datetime',
        'failed_at'             => 'datetime',
        'last_send_attempt_at'  => 'datetime',
        'send_attempts'         => 'integer',
    ];

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function bookingIntents(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(BookingIntent::class, 'conversation_message_id');
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

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

    // -------------------------------------------------------------------------
    // Direction helpers
    // -------------------------------------------------------------------------

    public function isInbound(): bool
    {
        return $this->direction === MessageDirection::Inbound;
    }

    public function isOutbound(): bool
    {
        return $this->direction === MessageDirection::Outbound;
    }

    // -------------------------------------------------------------------------
    // Delivery status helpers (Tahap 8)
    // -------------------------------------------------------------------------

    /**
     * Mark the message as pending delivery.
     * Called before a send attempt is made.
     */
    public function markPending(): void
    {
        $this->update([
            'delivery_status' => MessageDeliveryStatus::Pending,
            'delivery_error'  => null,
        ]);
    }

    /**
     * Mark the message as successfully sent to the WhatsApp provider.
     * For Tahap 8, delivered_at is set equal to sent_at (no provider webhook yet).
     *
     * @param  array<string, mixed>  $providerPayload  Raw API response to merge into raw_payload
     */
    public function markSent(?string $waMessageId = null, array $providerPayload = []): void
    {
        $now     = now();
        $updates = [
            'delivery_status' => MessageDeliveryStatus::Sent,
            'delivery_error'  => null,
            'sent_at'         => $now,
            'delivered_at'    => $now, // Optimistic: treat sent = delivered until webhook support
        ];

        if ($waMessageId !== null) {
            $updates['wa_message_id'] = $waMessageId;
        }

        if (! empty($providerPayload)) {
            $updates['raw_payload'] = array_merge($this->raw_payload ?? [], $providerPayload);
        }

        $this->update($updates);
    }

    /**
     * Mark the message as permanently failed to deliver.
     *
     * @param  array<string, mixed>  $providerPayload  Raw error response to merge into raw_payload
     */
    public function markFailed(?string $error = null, array $providerPayload = []): void
    {
        $updates = [
            'delivery_status' => MessageDeliveryStatus::Failed,
            'delivery_error'  => $error,
            'failed_at'       => now(),
        ];

        if (! empty($providerPayload)) {
            $updates['raw_payload'] = array_merge($this->raw_payload ?? [], $providerPayload);
        }

        $this->update($updates);
    }

    /**
     * Mark the message as skipped (e.g. sender disabled, empty text, no customer phone).
     *
     * @param  array<string, mixed>  $providerPayload  Optional context to merge into raw_payload
     */
    public function markSkipped(?string $reason = null, array $providerPayload = []): void
    {
        $updates = [
            'delivery_status' => MessageDeliveryStatus::Skipped,
            'delivery_error'  => $reason,
        ];

        if (! empty($providerPayload)) {
            $updates['raw_payload'] = array_merge($this->raw_payload ?? [], $providerPayload);
        }

        $this->update($updates);
    }

    // -------------------------------------------------------------------------
    // Retry / resend helpers (Tahap 9)
    // -------------------------------------------------------------------------

    /**
     * Increment the send_attempts counter and record the attempt timestamp.
     * Called at the start of each SendWhatsAppMessageJob execution.
     */
    public function incrementSendAttempt(): void
    {
        $this->increment('send_attempts');
        $this->update(['last_send_attempt_at' => now()]);
    }

    /**
     * Whether this message is eligible for a manual resend from the admin dashboard.
     *
     * Rules:
     *  - Must be outbound and sent by bot or agent.
     *  - Already successfully sent (Sent/Delivered) → not resendable.
     *  - Failed delivery → resendable.
     *  - Skipped due to sender_disabled or no_valid_customer_phone → resendable
     *    (admin may have fixed the configuration since).
     *  - All other Skipped reasons (e.g. empty_text, max_attempts_exceeded) → not resendable.
     */
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

        // Skipped for recoverable operational reasons
        if ($this->delivery_status === MessageDeliveryStatus::Skipped
            && in_array($this->delivery_error, ['sender_disabled', 'no_valid_customer_phone'], true)) {
            return true;
        }

        return false;
    }

    // -------------------------------------------------------------------------
    // Other helpers
    // -------------------------------------------------------------------------

    /**
     * Return a truncated preview of the message text.
     * Useful for summaries and logging without exposing full content.
     */
    public function textPreview(int $maxLength = 100): string
    {
        $text = $this->message_text ?? '';

        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        return mb_substr($text, 0, $maxLength) . '…';
    }

    /**
     * Stamp this message with the AI-classified intent and confidence score.
     * Used after IntentClassifierService runs on an inbound message.
     */
    public function tagWithAiResult(string $intent, float $confidence): bool
    {
        $this->ai_intent     = $intent;
        $this->ai_confidence = $confidence;

        return $this->save();
    }
}
