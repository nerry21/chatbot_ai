<?php

namespace App\Models;

use App\Enums\AuditActionType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    /**
     * Only `created_at` is stored — audit records are immutable.
     */
    const UPDATED_AT = null;

    protected $fillable = [
        'actor_user_id',
        'action_type',
        'auditable_type',
        'auditable_id',
        'conversation_id',
        'message',
        'context',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'context'    => 'array',
        'created_at' => 'datetime',
    ];

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    public function scopeForAction(Builder $query, AuditActionType|string $action): Builder
    {
        $value = $action instanceof AuditActionType ? $action->value : $action;

        return $query->where('action_type', $value);
    }

    public function scopeForConversation(Builder $query, int $conversationId): Builder
    {
        return $query->where('conversation_id', $conversationId);
    }

    public function scopeForActor(Builder $query, int $userId): Builder
    {
        return $query->where('actor_user_id', $userId);
    }
}
