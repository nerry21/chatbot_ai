<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerAlias extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'alias_name',
        'source',
    ];

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    public function scopeBySource(
        \Illuminate\Database\Eloquent\Builder $query,
        string $source,
    ): \Illuminate\Database\Eloquent\Builder {
        return $query->where('source', $source);
    }
}
