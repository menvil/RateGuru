<?php

namespace App\Models;

use App\Enums\TrafficStepValueMode;
use Carbon\CarbonImmutable;
use Database\Factories\TrafficStepValueFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A step of a game whose worth the administrator set by hand.
 *
 * @property int $id
 * @property int $game_id
 * @property string $step
 * @property string $value
 * @property TrafficStepValueMode $mode
 * @property string|null $note
 * @property int $set_by_user_id
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class TrafficStepValue extends Model
{
    /** @use HasFactory<TrafficStepValueFactory> */
    use HasFactory;

    protected $table = 'traffic_step_values';

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'game_id' => 'integer',
            'value' => 'decimal:4',
            'mode' => TrafficStepValueMode::class,
            'set_by_user_id' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Game, $this> */
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class, 'game_id');
    }
}
