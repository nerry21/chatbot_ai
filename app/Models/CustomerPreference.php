<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerPreference extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'key',
        'value',
        'value_type',
        'confidence',
        'source',
        'metadata',
        'last_seen_at',
    ];

    protected $casts = [
        'confidence'   => 'decimal:3',
        'metadata'     => 'array',
        'last_seen_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function scopeForCustomer(Builder $query, int $customerId): Builder
    {
        return $query->where('customer_id', $customerId);
    }

    public function scopeForKey(Builder $query, string $key): Builder
    {
        return $query->where('key', $key);
    }

    public function scopeReliable(Builder $query): Builder
    {
        return $query->where('confidence', '>=', 0.5);
    }

    /**
     * Cast `value` according to `value_type`.
     */
    public function getTypedValue(): mixed
    {
        $raw = $this->value;

        if ($raw === null) {
            return null;
        }

        return match ($this->value_type) {
            'int'  => (int) $raw,
            'bool' => $this->castBool($raw),
            'json' => json_decode((string) $raw, true),
            default => (string) $raw,
        };
    }

    private function castBool(mixed $raw): bool
    {
        if (is_bool($raw)) {
            return $raw;
        }

        $normalized = strtolower(trim((string) $raw));

        return match ($normalized) {
            '1', 'true', 'yes', 'y'  => true,
            '0', 'false', 'no', 'n', '' => false,
            default => (bool) $raw,
        };
    }
}
