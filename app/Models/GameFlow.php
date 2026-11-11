<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\GameFlowFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The screens around the game a visitor moves through, in order. A version
 * publishes one flow and keeps a snapshot of it. A flow a version used is
 * archived, never deleted.
 *
 * @property int $id
 * @property int $game_id
 * @property string $name
 * @property int $position
 * @property CarbonImmutable|null $archived_at
 * @property int $lock_version
 * @property CarbonImmutable $updated_at
 */
class GameFlow extends Model
{
    /** @use HasFactory<GameFlowFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $guarded = ['id', 'game_id', 'lock_version'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'position' => 0,
        'lock_version' => 1,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'game_id' => 'integer',
            'position' => 'integer',
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

    /** @return HasMany<GameFlowStep, $this> */
    public function steps(): HasMany
    {
        return $this->hasMany(GameFlowStep::class, 'flow_id')->orderBy('position')->orderBy('id');
    }

    /** @return HasMany<GameVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(GameVersion::class, 'flow_id');
    }
}
