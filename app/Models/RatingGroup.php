<?php

namespace App\Models;

use App\Support\Translations\TranslatableField;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RatingGroup extends Model
{
    use HasFactory;

    /** The fewest active options any group's range may ask for. */
    public const FEWEST_OPTIONS = 2;

    /** The most active options any group's range may allow: the vote form stays short. */
    public const MOST_OPTIONS = 10;

    protected $fillable = [
        'key',
        'label',
        'label_translations',
        'description',
        'description_translations',
        'min_options',
        'max_options',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'label_translations' => 'array',
            'description_translations' => 'array',
        ];
    }

    public function translatedLabel(?string $locale = null): string
    {
        return TranslatableField::resolve($this->label_translations, $this->label, $locale);
    }

    /** @return HasMany<RatingOption, $this> */
    public function options(): HasMany
    {
        return $this->hasMany(RatingOption::class);
    }

    /** @return HasMany<RatingVote, $this> */
    public function votes(): HasMany
    {
        return $this->hasMany(RatingVote::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Whether this many active options sit inside the group's range, as an active group's must. */
    public function holdsActiveOptions(int $count): bool
    {
        return $count >= $this->min_options && $count <= $this->max_options;
    }

    /** Whether a group with this many active options can take one more without passing its maximum. */
    public function hasRoomForActiveOption(int $active): bool
    {
        return $active < $this->max_options;
    }

    /** Whether a group with this many active options can lose one without falling under its minimum. */
    public function canSpareActiveOption(int $active): bool
    {
        return $active > $this->min_options;
    }
}
