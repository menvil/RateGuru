<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One working-out of the chances, from which matured visits and at which
 * prices.
 *
 * @property int $id
 * @property string $status
 * @property string $reason
 * @property int $price_set_id
 * @property CarbonImmutable $matured_before
 * @property int $visits_scanned
 * @property array<string, mixed>|null $confirmation_curve
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $finished_at
 * @property string|null $error
 */
class TrafficChanceRun extends Model
{
    protected $table = 'traffic_chance_runs';

    /** @var list<string> */
    protected $guarded = ['id'];

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'price_set_id' => 'integer',
            'matured_before' => 'immutable_datetime',
            'visits_scanned' => 'integer',
            'confirmation_curve' => 'array',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<TrafficStepChance, $this> */
    public function chances(): HasMany
    {
        return $this->hasMany(TrafficStepChance::class, 'run_id');
    }
}
