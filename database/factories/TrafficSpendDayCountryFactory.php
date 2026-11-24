<?php

namespace Database\Factories;

use App\Models\TrafficCampaign;
use App\Models\TrafficSource;
use App\Models\TrafficSpendDayCountry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrafficSpendDayCountry>
 */
class TrafficSpendDayCountryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'source_id' => TrafficSource::factory(),
            'network_day' => now('UTC')->format('Y-m-d'),
            'campaign_id' => fn (array $attributes) => TrafficCampaign::factory()->state(['source_id' => $attributes['source_id']]),
            'site_ref' => '',
            'country' => 'US',
            'impressions' => 100,
            'amount' => '1',
        ];
    }
}
