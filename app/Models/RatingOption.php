<?php

namespace App\Models;

use App\Support\Translations\TranslatableField;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property Carbon|null $archived_at when Archive took it away from voters; null while it is not archived
 */
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

    /** @return BelongsTo<RatingGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(RatingGroup::class, 'rating_group_id');
    }

    /** @return HasMany<RatingVote, $this> */
    public function votes(): HasMany
    {
        return $this->hasMany(RatingVote::class);
    }

    /** @return HasMany<PostAuthorAnswer, $this> */
    public function authorAnswers(): HasMany
    {
        return $this->hasMany(PostAuthorAnswer::class, 'rating_option_id');
    }

    /**
     * Whether anything refers to the option — a vote, or an author's answer
     * to their own post. Such an option is archived, never deleted: the rows
     * that point at it would lose their meaning, and the database refuses it.
     */
    public function isInUse(): bool
    {
        return $this->votes()->exists() || $this->authorAnswers()->exists();
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
