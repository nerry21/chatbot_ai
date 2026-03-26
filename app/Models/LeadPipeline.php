<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadPipeline extends Model
{
    protected $fillable = [
        'customer_id',
        'conversation_id',
        'booking_request_id',
        'stage',
        'owner_admin_id',
        'notes',
    ];

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function bookingRequest(): BelongsTo
    {
        return $this->belongsTo(BookingRequest::class);
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    public function scopeAtStage(Builder $query, string $stage): Builder
    {
        return $query->where('stage', $stage);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNotIn('stage', ['completed', 'cancelled']);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    public function isTerminal(): bool
    {
        return in_array($this->stage, ['completed', 'cancelled'], true);
    }
}
