<?php

namespace App\Models;

use App\Enums\TrafficSpendOrigin;
use Carbon\CarbonImmutable;
use Database\Factories\TrafficSpendDayFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a source spent on one of its days — by campaign, site or zone where the
 * figure says — and how much of it found visits to go to.
 *
 * @property int $id
 * @property int $source_id
 * @property string $network_day
 * @property TrafficSpendOrigin $origin
 * @property int|null $campaign_id
 * @property string $site_ref
 * @property string $zone_ref
 * @property string $amount
 * @property string $allocated_amount
 * @property bool $counted
 * @property int $matched_visits
 * @property int|null $reported_clicks
 * @property int|null $reported_impressions
 * @property int|null $import_id
 * @property int|null $entered_by_user_id
 * @property CarbonImmutable|null $allocated_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class TrafficSpendDay extends Model
{
    /** @use HasFactory<TrafficSpendDayFactory> */
    use HasFactory;

    protected $table = 'traffic_spend_days';

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'source_id' => 'integer',
            'origin' => TrafficSpendOrigin::class,
            'campaign_id' => 'integer',
            'amount' => 'decimal:8',
            'allocated_amount' => 'decimal:8',
            'counted' => 'boolean',
            'matched_visits' => 'integer',
            'reported_clicks' => 'integer',
            'reported_impressions' => 'integer',
            'import_id' => 'integer',
            'entered_by_user_id' => 'integer',
            'allocated_at' => 'immutable_datetime',
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

    /** @return BelongsTo<TrafficSpendImport, $this> */
    public function import(): BelongsTo
    {
        return $this->belongsTo(TrafficSpendImport::class, 'import_id');
    }
}
