<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingSeatReservation extends Model
{
    use HasFactory;

    protected $fillable = [
        'booking_request_id',
        'departure_date',
        'departure_time',
        'seat_code',
        'expires_at',
    ];

    protected $casts = [
        'departure_date' => 'date',
        'expires_at'     => 'datetime',
    ];

    public function bookingRequest(): BelongsTo
    {
        return $this->belongsTo(BookingRequest::class);
    }

    public function scopeActive(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where(function ($q): void {
            $q->whereNull('expires_at')
                ->orWhere('expires_at', '>', now());
        });
    }
}
