<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Customer extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'phone_e164',
        'email',
        'mobile_user_id',
        'mobile_device_id',
        'preferred_channel',
        'avatar_url',
        'preferred_pickup',
        'preferred_destination',
        'preferred_departure_time',
        'total_bookings',
        'total_spent',
        'last_interaction_at',
        'crm_contact_id',
        'notes',
        'status',
    ];

    protected $casts = [
        'total_spent'              => 'decimal:2',
        'total_bookings'           => 'integer',
        'last_interaction_at'      => 'datetime',
        'preferred_departure_time' => 'datetime',
    ];

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function mobileAccessTokens(): HasMany
    {
        return $this->hasMany(MobileAccessToken::class);
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(CustomerAlias::class);
    }

    public function crmContact(): HasOne
    {
        return $this->hasOne(CrmContact::class);
    }

    public function tags(): HasMany
    {
        return $this->hasMany(CustomerTag::class);
    }

    public function adminNotes(): MorphMany
    {
        return $this->morphMany(AdminNote::class, 'noteable')->latest('created_at');
    }

    public function leadPipelines(): HasMany
    {
        return $this->hasMany(LeadPipeline::class);
    }

    public function bookingRequests(): HasMany
    {
        return $this->hasMany(BookingRequest::class);
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    public function scopeActive(
        \Illuminate\Database\Eloquent\Builder $query,
    ): \Illuminate\Database\Eloquent\Builder {
        return $query->where('status', 'active');
    }

    public function scopeByPhone(
        \Illuminate\Database\Eloquent\Builder $query,
        string $phoneE164,
    ): \Illuminate\Database\Eloquent\Builder {
        return $query->where('phone_e164', $phoneE164);
    }

    public function scopeByMobileUserId(
        \Illuminate\Database\Eloquent\Builder $query,
        string $mobileUserId,
    ): \Illuminate\Database\Eloquent\Builder {
        return $query->where('mobile_user_id', $mobileUserId);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    public function touchLastInteraction(): bool
    {
        $this->last_interaction_at = now();
        return $this->save();
    }

    /**
     * Add an alias for this customer.
     * Silently returns the existing record if the alias already exists.
     */
    public function addAlias(string $aliasName, ?string $source = null): CustomerAlias
    {
        /** @var CustomerAlias $alias */
        $alias = $this->aliases()->firstOrCreate(
            ['alias_name' => $aliasName],
            ['source'     => $source],
        );

        return $alias;
    }

    /**
     * Check whether the customer has a specific alias (case-insensitive).
     */
    public function hasAlias(string $name): bool
    {
        return $this->aliases()
            ->whereRaw('LOWER(alias_name) = ?', [mb_strtolower($name)])
            ->exists();
    }

    /**
     * Add a CRM tag. Idempotent — safe to call multiple times with the same tag.
     */
    public function addTag(string $tag): void
    {
        $this->tags()->firstOrCreate(['tag' => $tag]);
    }

    /**
     * Check whether the customer currently holds a given CRM tag.
     */
    public function hasTag(string $tag): bool
    {
        return $this->tags()->where('tag', $tag)->exists();
    }

    public function hasSyntheticMobilePhone(): bool
    {
        return str_starts_with((string) $this->phone_e164, 'mlc:');
    }

    public function getDisplayContactAttribute(): string
    {
        if (! $this->hasSyntheticMobilePhone() && filled($this->phone_e164)) {
            return (string) $this->phone_e164;
        }

        if (filled($this->email)) {
            return (string) $this->email;
        }

        if (filled($this->mobile_user_id)) {
            return (string) $this->mobile_user_id;
        }

        return '-';
    }
}
