<?php

namespace Database\Factories;

use App\Enums\TrafficSpendOrigin;
use App\Models\TrafficSource;
use App\Models\TrafficSpendDay;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrafficSpendDay>
 */
class TrafficSpendDayFactory extends Factory
{
    public function definition(): array
    {
        return [
            'source_id' => TrafficSource::factory(),
            'network_day' => now('UTC')->format('Y-m-d'),
            'origin' => TrafficSpendOrigin::Manual,
            'site_ref' => '',
            'zone_ref' => '',
            'amount' => '10',
            'allocated_amount' => '0',
            'counted' => false,
            'matched_visits' => 0,
        ];
    }
}
