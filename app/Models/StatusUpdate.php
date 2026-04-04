<?php

namespace App\Models;

use App\Support\MediaUrlNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class StatusUpdate extends Model
{
    protected $fillable = [
        'user_id',
        'customer_id',
        'author_type',
        'status_type',
        'text',
        'caption',
        'background_color',
        'text_color',
        'font_style',
        'media_disk',
        'media_path',
        'media_mime_type',
        'media_original_name',
        'media_size_bytes',
        'duration_seconds',
        'music_meta',
        'audience_scope',
        'is_active',
        'posted_at',
        'expires_at',
    ];

    protected $casts = [
        'music_meta' => 'array',
        'is_active' => 'boolean',
        'posted_at' => 'datetime',
        'expires_at' => 'datetime',
        'media_size_bytes' => 'integer',
        'duration_seconds' => 'integer',
    ];

    public function authorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function authorCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function views(): HasMany
    {
        return $this->hasMany(StatusUpdateView::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->where('is_active', true)
            ->whereNotNull('posted_at')
            ->where(function (Builder $builder): void {
                $builder->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
    }

    public function getMediaUrlAttribute(): ?string
    {
        if (blank($this->media_path) || blank($this->media_disk)) {
            return null;
        }

        return MediaUrlNormalizer::normalize(
            Storage::disk((string) $this->media_disk)->url((string) $this->media_path),
        );
    }

    public function getAuthorNameAttribute(): string
    {
        if ($this->author_type === 'customer') {
            return (string) ($this->authorCustomer?->name ?: 'Customer');
        }

        return (string) ($this->authorUser?->name ?: 'Admin Jet');
    }
}
