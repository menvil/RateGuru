<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\TrafficBlockListingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where a block stands on one campaign's list, as RateGuru last wrote or read
 * that list: on it or not. One not on it says why — the network refused it
 * (a TrafficConnectorFailure), it was taken off in the network's panel
 * (TAKEN_OFF), or nothing: never sent there, or taken off by RateGuru. One on
 * it says why taking it off failed, if it did.
 *
 * @property int $id
 * @property int $block_id
 * @property int $campaign_id
 * @property bool $listed
 * @property string|null $error
 * @property CarbonImmutable $checked_at
 */
class TrafficBlockListing extends Model
{
    /** @use HasFactory<TrafficBlockListingFactory> */
    use HasFactory;

    /** Off the campaign's list since RateGuru last saw it there: taken off in the network's panel. */
    public const string TAKEN_OFF = 'taken_off';

    protected $table = 'traffic_block_listings';

    /** @var list<string> */
    protected $guarded = ['id'];

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'block_id' => 'integer',
            'campaign_id' => 'integer',
            'listed' => 'boolean',
            'checked_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<TrafficBlock, $this> */
    public function block(): BelongsTo
    {
        return $this->belongsTo(TrafficBlock::class, 'block_id');
    }

    /** @return BelongsTo<TrafficCampaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(TrafficCampaign::class, 'campaign_id');
    }
}
