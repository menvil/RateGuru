<?php

namespace App\Models;

use App\Enums\TrafficCampaignStage;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A stretch of time a campaign spent in one stage.
 *
 * @property int $id
 * @property int $campaign_id
 * @property TrafficCampaignStage $stage
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $ended_at
 */
class TrafficCampaignStagePeriod extends Model
{
    protected $table = 'traffic_campaign_stages';

    /** @var list<string> */
    protected $guarded = ['id'];

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'campaign_id' => 'integer',
            'stage' => TrafficCampaignStage::class,
            'started_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<TrafficCampaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(TrafficCampaign::class, 'campaign_id');
    }
}
