<?php

namespace App\Models;

use App\Enums\TrafficActor;
use App\Enums\TrafficDevice;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A campaign's price for one device from a moment on.
 *
 * @property int $id
 * @property int $campaign_id
 * @property TrafficDevice $device
 * @property string $bid_model
 * @property string $price
 * @property CarbonImmutable $effective_from
 * @property TrafficActor $actor
 * @property int|null $actor_user_id
 */
class TrafficCampaignPrice extends Model
{
    protected $table = 'traffic_campaign_prices';

    /** @var list<string> */
    protected $guarded = ['id'];

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'campaign_id' => 'integer',
            'device' => TrafficDevice::class,
            'price' => 'decimal:6',
            'effective_from' => 'immutable_datetime',
            'actor' => TrafficActor::class,
            'actor_user_id' => 'integer',
        ];
    }

    /** @return BelongsTo<TrafficCampaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(TrafficCampaign::class, 'campaign_id');
    }
}
