<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\GameContentSetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named collection of rounds with its own scoring and result profiles. A
 * version publishes one set and keeps a snapshot of it, so editing the set
 * never changes a published version. A set a version used is archived,
 * never deleted.
 *
 * @property int $id
 * @property int $game_id
 * @property string $name
 * @property int $position
 * @property int $points_per_round
 * @property int $crowd_min_votes
 * @property int $crowd_min_gap
 * @property CarbonImmutable|null $archived_at
 * @property int $lock_version
 * @property CarbonImmutable $updated_at
 */
#[Fillable(['name', 'points_per_round', 'crowd_min_votes', 'crowd_min_gap'])]
class GameContentSet extends Model
{
    /** @use HasFactory<GameContentSetFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'position' => 0,
        'points_per_round' => 1,
        'crowd_min_votes' => 10,
        'crowd_min_gap' => 0,
        'lock_version' => 1,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'game_id' => 'integer',
            'position' => 'integer',
            'points_per_round' => 'integer',
            'crowd_min_votes' => 'integer',
            'crowd_min_gap' => 'integer',
            'archived_at' => 'immutable_datetime',
            'lock_version' => 'integer',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /** @return BelongsTo<Game, $this> */
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    /** @return HasMany<GameRound, $this> */
    public function rounds(): HasMany
    {
        return $this->hasMany(GameRound::class, 'content_set_id')->orderBy('position')->orderBy('id');
    }

    /** @return HasMany<GameResultProfile, $this> */
    public function profiles(): HasMany
    {
        return $this->hasMany(GameResultProfile::class, 'content_set_id')->orderBy('position')->orderBy('id');
    }

    /** @return HasMany<GameVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(GameVersion::class, 'content_set_id');
    }
}
