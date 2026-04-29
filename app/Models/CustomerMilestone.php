<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerMilestone extends Model
{
    protected $fillable = [
        'customer_id',
        'milestone_key',
        'milestone_category',
        'metadata',
        'achieved_at',
        'acknowledged_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'achieved_at' => 'datetime',
        'acknowledged_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function isAcknowledged(): bool
    {
        return $this->acknowledged_at !== null;
    }
}
