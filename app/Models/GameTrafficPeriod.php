<?php

namespace App\Models;

use App\Enums\GameAudience;
use Carbon\CarbonImmutable;
use Database\Factories\GameTrafficPeriodFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A stretch of time one allocation of an audience's visitors was in effect,
 * open until the next allocation is applied. Its versions carry their share
 * on the pivot; a period without any is an explicit "no traffic".
 *
 * @property int $id
 * @property int $game_id
 * @property GameAudience $audience
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $ended_at
 * @property int $started_by_user_id
 */
class GameTrafficPeriod extends Model
{
    /** @use HasFactory<GameTrafficPeriodFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'game_id' => 'integer',
            'audience' => GameAudience::class,
            'started_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
            'started_by_user_id' => 'integer',
        ];
    }

    public function isOpen(): bool
    {
        return $this->ended_at === null;
    }

    /**
     * The versions sharing the period, each with its `share_percent`, in
     * version order.
     *
     * @return BelongsToMany<GameVersion, $this>
     */
    public function versions(): BelongsToMany
    {
        return $this->belongsToMany(GameVersion::class, 'game_traffic_shares', 'period_id', 'version_id')
            ->withPivot('share_percent')
            ->orderBy('game_versions.number');
    }

    /**
     * The shares, version id to percentage, in version order.
     *
     * @return array<int, int>
     */
    public function shares(): array
    {
        $shares = [];

        foreach ($this->versions as $version) {
            /** @var object{share_percent: int|string} $pivot */
            $pivot = $version->getRelation('pivot');
            $shares[(int) $version->id] = (int) $pivot->share_percent;
        }

        return $shares;
    }

    /** @return HasMany<GameAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(GameAssignment::class, 'period_id');
    }

    /** @return BelongsTo<Game, $this> */
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    /** @return BelongsTo<User, $this> */
    public function startedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by_user_id');
    }
}
