<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrmContact extends Model
{
    protected $fillable = [
        'customer_id',
        'provider',
        'external_contact_id',
        'last_synced_at',
        'sync_status',
        'sync_payload',
    ];

    protected $casts = [
        'last_synced_at' => 'datetime',
        'sync_payload'   => 'array',
    ];

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    // -------------------------------------------------------------------------
    // Status helpers
    // -------------------------------------------------------------------------

    public function isPending(): bool
    {
        return $this->sync_status === 'pending';
    }

    public function isSynced(): bool
    {
        return $this->sync_status === 'synced';
    }

    public function isLocalOnly(): bool
    {
        return $this->sync_status === 'local_only';
    }

    public function hasFailed(): bool
    {
        return $this->sync_status === 'failed';
    }

    // -------------------------------------------------------------------------
    // State transitions
    // -------------------------------------------------------------------------

    public function markSynced(string $externalId, array $payload = []): void
    {
        $this->update([
            'external_contact_id' => $externalId,
            'sync_status'         => 'synced',
            'last_synced_at'      => now(),
            'sync_payload'        => $payload,
        ]);
    }

    public function markFailed(string $error = ''): void
    {
        $this->update([
            'sync_status'  => 'failed',
            'sync_payload' => array_merge($this->sync_payload ?? [], [
                'last_error' => $error,
                'failed_at'  => now()->toDateTimeString(),
            ]),
        ]);
    }

    public function markLocalOnly(): void
    {
        $this->update(['sync_status' => 'local_only']);
    }
}
