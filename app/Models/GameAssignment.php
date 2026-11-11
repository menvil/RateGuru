<?php

namespace App\Models;

use App\Enums\GameAudience;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Which version a visitor of an audience was given, kept while that version
 * still has a share and can be played, so they get it again when they come
 * back. Ended, it leaves `active_key` empty and a newer assignment takes over.
 *
 * @property int $id
 * @property int $game_id
 * @property GameAudience $audience
 * @property int|null $user_id
 * @property string|null $visitor_hash
 * @property string|null $active_key
 * @property int $version_id
 * @property int $period_id
 * @property CarbonImmutable $assigned_at
 * @property CarbonImmutable $last_seen_at
 * @property CarbonImmutable|null $ended_at
 */
class GameAssignment extends Model
{
    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'game_id' => 'integer',
            'audience' => GameAudience::class,
            'user_id' => 'integer',
            'version_id' => 'integer',
            'period_id' => 'integer',
            'assigned_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<GameVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(GameVersion::class);
    }

    /** @return BelongsTo<GameTrafficPeriod, $this> */
    public function period(): BelongsTo
    {
        return $this->belongsTo(GameTrafficPeriod::class);
    }

    /** @return BelongsTo<Game, $this> */
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }
}
