<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class KnowledgeArticle extends Model
{
    protected $fillable = [
        'category',
        'title',
        'slug',
        'content',
        'keywords',
        'is_active',
    ];

    protected $casts = [
        'keywords'  => 'array',
        'is_active' => 'boolean',
    ];

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeInCategory(Builder $query, string $category): Builder
    {
        return $query->where('category', $category);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Generate a unique slug from the given title.
     * Appends a numeric suffix if the slug is already taken.
     */
    public static function generateSlug(string $title, ?int $exceptId = null): string
    {
        $base  = Str::slug($title);
        $slug  = $base;
        $count = 1;

        while (
            static::where('slug', $slug)
                ->when($exceptId, fn (Builder $q) => $q->where('id', '!=', $exceptId))
                ->exists()
        ) {
            $slug = $base . '-' . $count++;
        }

        return $slug;
    }

    /**
     * Return keywords as a comma-separated string for form display.
     */
    public function keywordsAsString(): string
    {
        return implode(', ', $this->keywords ?? []);
    }
}
