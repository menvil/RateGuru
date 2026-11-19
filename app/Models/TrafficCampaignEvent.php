<?php

namespace App\Models;

use App\Enums\TrafficActor;
use App\Enums\TrafficCampaignEventKind;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a campaign's history.
 *
 * @property int $id
 * @property int $campaign_id
 * @property TrafficCampaignEventKind $kind
 * @property string $summary
 * @property TrafficActor $actor
 * @property int|null $actor_user_id
 * @property CarbonImmutable $occurred_at
 */
class TrafficCampaignEvent extends Model
{
    protected $table = 'traffic_campaign_events';

    /** @var list<string> */
    protected $guarded = ['id'];

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'campaign_id' => 'integer',
            'kind' => TrafficCampaignEventKind::class,
            'actor' => TrafficActor::class,
            'actor_user_id' => 'integer',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<TrafficCampaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(TrafficCampaign::class, 'campaign_id');
    }
}
