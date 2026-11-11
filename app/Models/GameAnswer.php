<?php

namespace App\Models;

use App\Enums\GameAnswerOutcome;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A pick, one per round of an attempt, in order. Its outcome was read from the
 * version's snapshot when it was made.
 *
 * @property int $id
 * @property int $attempt_id
 * @property int $round_index
 * @property string $round_key
 * @property int $option_position
 * @property GameAnswerOutcome $outcome
 * @property int $points
 * @property CarbonImmutable $answered_at
 */
class GameAnswer extends Model
{
    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'attempt_id' => 'integer',
            'round_index' => 'integer',
            'option_position' => 'integer',
            'outcome' => GameAnswerOutcome::class,
            'points' => 'integer',
            'answered_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<GameAttempt, $this> */
    public function attempt(): BelongsTo
    {
        return $this->belongsTo(GameAttempt::class, 'attempt_id');
    }
}
