<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RatingGroup extends Model
{
    use HasFactory;

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

    public function options(): HasMany
    {
        return $this->hasMany(RatingOption::class);
    }

    public function votes(): HasMany
    {
        return $this->hasMany(RatingVote::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
