<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\TrafficSpendDayCountryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * What a network billed for one campaign on one site in one country on one
 * of its days, as its API reports it. Read only to set a country's paid
 * impressions beside its visits: spend is shared out from TrafficSpendDay,
 * never from this.
 *
 * @property int $id
 * @property int $source_id
 * @property string $network_day
 * @property int $campaign_id
 * @property string $site_ref
 * @property string $country
 * @property int $impressions
 * @property string $amount
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class TrafficSpendDayCountry extends Model
{
    /** @use HasFactory<TrafficSpendDayCountryFactory> */
    use HasFactory;

    protected $table = 'traffic_spend_day_countries';

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'impressions' => 'integer',
            'amount' => 'decimal:8',
        ];
    }
}
