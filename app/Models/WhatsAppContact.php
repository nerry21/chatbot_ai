<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class WhatsAppContact extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'whatsapp_contacts';

    protected $fillable = [
        'user_id',
        'customer_id',
        'conversation_id',
        'first_name',
        'last_name',
        'display_name',
        'phone_e164',
        'phone_raw',
        'email',
        'country_code',
        'is_whatsapp_verified',
        'sync_to_device',
        'source',
        'avatar_url',
        'notes',
        'last_synced_at',
    ];

    protected $casts = [
        'is_whatsapp_verified' => 'boolean',
        'sync_to_device'       => 'boolean',
        'last_synced_at'       => 'datetime',
    ];

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'conversation_id');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    public function getFullNameAttribute(): string
    {
        $parts = array_filter([
            trim((string) $this->first_name),
            trim((string) $this->last_name),
        ]);

        return implode(' ', $parts);
    }

    public function getInitialsAttribute(): string
    {
        $name = trim((string) ($this->display_name ?: $this->full_name));
        if ($name === '') {
            return 'C';
        }

        $words = preg_split('/\s+/', $name) ?: [];
        $words = array_values(array_filter($words));

        if (count($words) >= 2) {
            return mb_strtoupper(
                mb_substr($words[0], 0, 1, 'UTF-8').mb_substr($words[1], 0, 1, 'UTF-8'),
                'UTF-8'
            );
        }

        return mb_strtoupper(mb_substr($name, 0, 1, 'UTF-8'), 'UTF-8');
    }
}