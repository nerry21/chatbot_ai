<?php

namespace App\Models;

use App\Enums\ConversationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'channel',
        'channel_conversation_id',
        'started_at',
        'last_message_at',
        'status',
        'current_intent',
        'summary',
        'needs_human',
        'escalation_reason',
        // Handoff management (Tahap 7)
        'handoff_mode',
        'handoff_admin_id',
        'handoff_at',
    ];

    protected $casts = [
        'started_at'      => 'datetime',
        'last_message_at' => 'datetime',
        'needs_human'     => 'boolean',
        'status'          => ConversationStatus::class,
        'handoff_at'      => 'datetime',
    ];

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ConversationMessage::class);
    }

    public function states(): HasMany
    {
        return $this->hasMany(ConversationState::class);
    }

    public function bookingRequests(): HasMany
    {
        return $this->hasMany(BookingRequest::class);
    }

    public function escalations(): HasMany
    {
        return $this->hasMany(Escalation::class);
    }

    public function leadPipelines(): HasMany
    {
        return $this->hasMany(LeadPipeline::class);
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', ConversationStatus::Active);
    }

    public function scopeForCustomer(Builder $query, int $customerId): Builder
    {
        return $query->where('customer_id', $customerId);
    }

    public function scopeOnChannel(Builder $query, string $channel): Builder
    {
        return $query->where('channel', $channel);
    }

    public function scopeNeedsHuman(Builder $query): Builder
    {
        return $query->where('needs_human', true);
    }

    public function scopeAdminTakeover(Builder $query): Builder
    {
        return $query->where('handoff_mode', 'admin');
    }

    // -------------------------------------------------------------------------
    // Status helpers
    // -------------------------------------------------------------------------

    public function isActive(): bool
    {
        return $this->status === ConversationStatus::Active;
    }

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }

    public function isEscalated(): bool
    {
        return $this->status === ConversationStatus::Escalated;
    }

    // -------------------------------------------------------------------------
    // Handoff helpers (Tahap 7)
    // -------------------------------------------------------------------------

    /**
     * Returns true when a human admin has taken over this conversation
     * and the bot auto-reply pipeline must be suppressed.
     */
    public function isAdminTakeover(): bool
    {
        return $this->handoff_mode === 'admin';
    }

    /**
     * Put the conversation into admin-takeover mode.
     * The bot pipeline will be suppressed until releaseToBot() is called.
     *
     * @param  int|null  $adminId  The authenticated user ID performing the takeover.
     */
    public function takeoverBy(?int $adminId = null): void
    {
        $this->update([
            'handoff_mode'     => 'admin',
            'handoff_admin_id' => $adminId,
            'handoff_at'       => now(),
            'needs_human'      => true,
        ]);
    }

    /**
     * Release the conversation back to the bot.
     * Does NOT change conversation status or close it.
     */
    public function releaseToBot(): void
    {
        $this->update([
            'handoff_mode'     => 'bot',
            'handoff_admin_id' => null,
            'handoff_at'       => now(),
            'needs_human'      => false,
        ]);
    }

    // -------------------------------------------------------------------------
    // Message helpers
    // -------------------------------------------------------------------------

    public function latestInboundMessage(): ?ConversationMessage
    {
        return $this->messages()
            ->inbound()
            ->orderByDesc('sent_at')
            ->first();
    }

    public function latestOutboundMessage(): ?ConversationMessage
    {
        return $this->messages()
            ->outbound()
            ->orderByDesc('sent_at')
            ->first();
    }

    // -------------------------------------------------------------------------
    // State helpers
    // -------------------------------------------------------------------------

    public function getStateValue(string $key, mixed $default = null): mixed
    {
        $state = $this->states()
            ->active()
            ->byKey($key)
            ->latest('updated_at')
            ->first();

        return $state !== null ? $state->state_value : $default;
    }

    // -------------------------------------------------------------------------
    // AI result helpers
    // -------------------------------------------------------------------------

    public function updateIntent(string|\App\Enums\IntentType $intent): bool
    {
        $this->current_intent = $intent instanceof \App\Enums\IntentType
            ? $intent->value
            : $intent;

        return $this->save();
    }

    public function updateSummary(string $summary): bool
    {
        if ($summary === '') {
            return true;
        }

        $this->summary = $summary;
        return $this->save();
    }

    // -------------------------------------------------------------------------
    // Timestamp helpers
    // -------------------------------------------------------------------------

    public function touchLastMessage(): bool
    {
        $this->last_message_at = now();
        return $this->save();
    }
}
