<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Escalation extends Model
{
    protected $fillable = [
        'conversation_id',
        'reason',
        'priority',
        'status',
        'assigned_admin_id',
        'summary',
        'resolved_at',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
    ];

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', 'open');
    }

    public function scopeByPriority(Builder $query, string $priority): Builder
    {
        return $query->where('priority', $priority);
    }

    public function scopeUnassigned(Builder $query): Builder
    {
        return $query->whereNull('assigned_admin_id');
    }

    // -------------------------------------------------------------------------
    // Status helpers
    // -------------------------------------------------------------------------

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    public function isAssigned(): bool
    {
        return $this->status === 'assigned';
    }

    public function isResolved(): bool
    {
        return $this->status === 'resolved';
    }

    public function isClosed(): bool
    {
        return $this->status === 'closed';
    }

    // -------------------------------------------------------------------------
    // State transitions
    // -------------------------------------------------------------------------

    public function markAssigned(?int $adminId = null): void
    {
        $this->update([
            'status'            => 'assigned',
            'assigned_admin_id' => $adminId,
        ]);
    }

    public function markResolved(): void
    {
        $this->update([
            'status'      => 'resolved',
            'resolved_at' => now(),
        ]);
    }

    public function markClosed(): void
    {
        $this->update(['status' => 'closed']);
    }
}
