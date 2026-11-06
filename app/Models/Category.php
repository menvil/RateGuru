<?php

namespace App\Models;

use App\Support\Translations\TranslatableField;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class Category extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected static function booted(): void
    {
        // AFTER COMMIT, not on the model event. Inside a transaction — which
        // DeleteCategoryAction uses — the event fires while the change is still
        // uncommitted, so a concurrent sidebar render could repopulate the cache
        // from rows that are about to disappear and keep a deleted category
        // visible for the full five-minute TTL. DB::afterCommit runs immediately
        // when there is no transaction, so the ordinary save path is unchanged.
        static::saved(fn () => DB::afterCommit(fn () => Cache::forget('sidebar-nav-categories')));
        static::deleted(fn () => DB::afterCommit(fn () => Cache::forget('sidebar-nav-categories')));
    }

    protected function casts(): array
    {
        return [
            'name_translations' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    public function translatedName(?string $locale = null): string
    {
        return TranslatableField::resolve($this->name_translations, $this->name, $locale);
    }

    /** @return HasMany<Post, $this> */
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }
}
