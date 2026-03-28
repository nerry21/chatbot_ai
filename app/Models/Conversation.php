<?php

namespace App\Models;

use App\Enums\ConversationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

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
        'human_takeover_at',
        'human_takeover_by',
        'bot_paused',
        'bot_paused_reason',
        'assigned_admin_id',
        'released_to_bot_at',
        'last_admin_intervention_at',
        'is_urgent',
        'urgent_marked_at',
        'urgent_marked_by',
        'closed_at',
        'closed_by',
        'close_reason',
        'reopened_at',
        'reopened_by',
    ];

    protected $casts = [
        'started_at'      => 'datetime',
        'last_message_at' => 'datetime',
        'needs_human'     => 'boolean',
        'bot_paused'      => 'boolean',
        'status'          => ConversationStatus::class,
        'handoff_at'      => 'datetime',
        'human_takeover_at' => 'datetime',
        'released_to_bot_at' => 'datetime',
        'last_admin_intervention_at' => 'datetime',
        'is_urgent' => 'boolean',
        'urgent_marked_at' => 'datetime',
        'closed_at' => 'datetime',
        'reopened_at' => 'datetime',
    ];

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function assignedAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_admin_id');
    }

    public function handoffAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handoff_admin_id');
    }

    public function urgentMarkedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'urgent_marked_by');
    }

    public function closedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function reopenedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reopened_by');
    }

    public function humanTakeoverByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'human_takeover_by');
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

    public function handoffs(): HasMany
    {
        return $this->hasMany(ConversationHandoff::class)->latest('happened_at');
    }

    public function tags(): HasMany
    {
        return $this->hasMany(ConversationTag::class)->latest('created_at');
    }

    public function adminNotes(): MorphMany
    {
        return $this->morphMany(AdminNote::class, 'noteable')->latest('created_at');
    }

    public function userReads(): HasMany
    {
        return $this->hasMany(ConversationUserRead::class);
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

    public function scopeHumanTakeoverActive(Builder $query): Builder
    {
        return $query
            ->where('handoff_mode', 'admin')
            ->where('bot_paused', true);
    }

    public function scopeAutomationSuppressed(Builder $query): Builder
    {
        return $query->where(function (Builder $builder): void {
            $builder->where('handoff_mode', 'admin')
                ->orWhere('bot_paused', true);
        });
    }

    public function scopeUrgent(Builder $query): Builder
    {
        return $query->where('is_urgent', true);
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

    public function isBotPaused(): bool
    {
        return (bool) $this->bot_paused;
    }

    public function isAutomationSuppressed(): bool
    {
        return $this->isAdminTakeover() || $this->isBotPaused();
    }

    public function currentOperationalMode(): string
    {
        if ($this->isTerminal()) {
            return 'closed';
        }

        if ($this->isAdminTakeover()) {
            return 'human_takeover';
        }

        if ($this->isBotPaused() || $this->needs_human || $this->isEscalated()) {
            return 'escalated';
        }

        return 'bot_active';
    }

    public function currentOperationalModeLabel(): string
    {
        return match ($this->currentOperationalMode()) {
            'human_takeover' => 'Human Takeover',
            'escalated' => 'Escalated',
            'closed' => 'Closed',
            default => 'Bot Active',
        };
    }

    public function currentOperationalModePalette(): string
    {
        return match ($this->currentOperationalMode()) {
            'human_takeover' => 'orange',
            'escalated' => 'red',
            'closed' => 'slate',
            default => 'green',
        };
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
    public function takeoverBy(?int $adminId = null, ?string $reason = null): void
    {
        $this->update([
            'handoff_mode'     => 'admin',
            'handoff_admin_id' => $adminId,
            'handoff_at'       => now(),
            'human_takeover_at' => now(),
            'human_takeover_by' => $adminId,
            'bot_paused' => true,
            'bot_paused_reason' => 'human_takeover',
            'assigned_admin_id' => $adminId,
            'released_to_bot_at' => null,
            'last_admin_intervention_at' => now(),
            'needs_human'      => true,
        ]);
    }

    /**
     * Release the conversation back to the bot.
     * Does NOT change conversation status or close it.
     */
    public function releaseToBot(?int $adminId = null): void
    {
        $updates = [
            'handoff_mode'     => 'bot',
            'handoff_admin_id' => null,
            'handoff_at'       => now(),
            'bot_paused' => false,
            'bot_paused_reason' => null,
            'assigned_admin_id' => null,
            'released_to_bot_at' => now(),
            'last_admin_intervention_at' => now(),
            'needs_human'      => false,
        ];

        if ($this->status === ConversationStatus::Escalated) {
            $updates['status'] = ConversationStatus::Active;
        }

        $this->update($updates);
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
            ->where('sender_type', '!=', \App\Enums\SenderType::System->value)
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
