<?php

namespace App\Models;

use App\Support\Translations\TranslatableField;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RatingOption extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'archived_at' => 'datetime',
            'label_translations' => 'array',
            'description_translations' => 'array',
        ];
    }

    public function translatedLabel(?string $locale = null): string
    {
        return TranslatableField::resolve($this->label_translations, $this->label, $locale);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(RatingGroup::class, 'rating_group_id');
    }

    public function votes(): HasMany
    {
        return $this->hasMany(RatingVote::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }
}
