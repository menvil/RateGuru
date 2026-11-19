<?php

namespace App\Models;

use App\Enums\TrafficBlockLevel;
use App\Enums\TrafficBlockMethod;
use App\Enums\TrafficBlockNetworkState;
use App\Enums\TrafficBlockVerdict;
use Carbon\CarbonImmutable;
use Database\Factories\TrafficBlockFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A blocked site or zone of a network, and how many clicks still came from it
 * afterwards.
 *
 * @property int $id
 * @property int $source_id
 * @property TrafficBlockLevel $level
 * @property string $ref
 * @property int|null $campaign_id
 * @property TrafficBlockMethod $method
 * @property TrafficBlockVerdict|null $verdict
 * @property string|null $reason
 * @property string|null $batch
 * @property TrafficBlockNetworkState $network_state
 * @property string|null $network_error
 * @property int $clicks_after
 * @property CarbonImmutable|null $last_click_after_at
 * @property int|null $blocked_by_user_id
 * @property CarbonImmutable $blocked_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class TrafficBlock extends Model
{
    /** @use HasFactory<TrafficBlockFactory> */
    use HasFactory;

    protected $table = 'traffic_blocks';

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'source_id' => 'integer',
            'level' => TrafficBlockLevel::class,
            'campaign_id' => 'integer',
            'method' => TrafficBlockMethod::class,
            'verdict' => TrafficBlockVerdict::class,
            'network_state' => TrafficBlockNetworkState::class,
            'clicks_after' => 'integer',
            'last_click_after_at' => 'immutable_datetime',
            'blocked_by_user_id' => 'integer',
            'blocked_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<TrafficSource, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(TrafficSource::class, 'source_id');
    }

    /** @return BelongsTo<TrafficCampaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(TrafficCampaign::class, 'campaign_id');
    }
}
