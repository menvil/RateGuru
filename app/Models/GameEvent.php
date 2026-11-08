<?php

namespace App\Models;

use App\Enums\GameAudience;
use App\Enums\GameEventType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * One tracked moment of a game, append-only, recorded by GameEventRecorder in
 * the transaction of the change it records. Ids and codes only.
 *
 * @property int $id
 * @property int $game_id
 * @property int|null $version_id
 * @property int|null $attempt_id
 * @property int|null $assignment_id
 * @property GameAudience $audience
 * @property int|null $user_id
 * @property string|null $visitor_hash
 * @property GameEventType $type
 * @property string|null $step_type
 * @property int|null $round_index
 * @property string|null $provider
 * @property CarbonImmutable $occurred_at
 */
class GameEvent extends Model
{
    public $timestamps = false;

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'game_id' => 'integer',
            'version_id' => 'integer',
            'attempt_id' => 'integer',
            'assignment_id' => 'integer',
            'audience' => GameAudience::class,
            'user_id' => 'integer',
            'type' => GameEventType::class,
            'round_index' => 'integer',
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
