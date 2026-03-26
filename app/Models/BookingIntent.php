<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingIntent extends Model
{
    use HasFactory;

    protected $fillable = [
        'booking_request_id',
        'conversation_message_id',
        'detected_intent',
        'confidence',
        'extracted_entities',
        'raw_ai_payload',
    ];

    protected $casts = [
        'confidence'         => 'decimal:2',
        'extracted_entities' => 'array',
        'raw_ai_payload'     => 'array',
    ];

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    public function bookingRequest(): BelongsTo
    {
        return $this->belongsTo(BookingRequest::class);
    }

    public function conversationMessage(): BelongsTo
    {
        return $this->belongsTo(ConversationMessage::class, 'conversation_message_id');
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    public function scopeByIntent(
        \Illuminate\Database\Eloquent\Builder $query,
        string $intent,
    ): \Illuminate\Database\Eloquent\Builder {
        return $query->where('detected_intent', $intent);
    }
}
