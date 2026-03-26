<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversationState extends Model
{
    use HasFactory;

    protected $fillable = [
        'conversation_id',
        'state_key',
        'state_value',
        'expires_at',
    ];

    protected $casts = [
        'state_value' => 'array',
        'expires_at'  => 'datetime',
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

    public function scopeActive(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where(function ($q) {
            $q->whereNull('expires_at')
              ->orWhere('expires_at', '>', now());
        });
    }

    public function scopeByKey(\Illuminate\Database\Eloquent\Builder $query, string $key): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('state_key', $key);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
