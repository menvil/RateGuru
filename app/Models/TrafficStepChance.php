<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The chance that a new visitor who reached a step ends up a confirmed
 * account, for one slice of one run.
 *
 * @property int $id
 * @property int $run_id
 * @property string $slice_key
 * @property int|null $game_id
 * @property int|null $source_id
 * @property string|null $channel
 * @property string|null $device
 * @property string|null $country_group
 * @property string $step
 * @property int $visitors
 * @property int $accounts
 * @property int $slice_accounts
 * @property string $chance
 * @property bool $thin
 * @property string $stands_on
 */
class TrafficStepChance extends Model
{
    protected $table = 'traffic_step_chances';

    /** @var list<string> */
    protected $guarded = ['id'];

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'run_id' => 'integer',
            'game_id' => 'integer',
            'source_id' => 'integer',
            'visitors' => 'integer',
            'accounts' => 'integer',
            'slice_accounts' => 'integer',
            'chance' => 'decimal:8',
            'thin' => 'boolean',
        ];
    }

    /** @return BelongsTo<TrafficChanceRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(TrafficChanceRun::class, 'run_id');
    }
}
