<?php

namespace Database\Factories;

use App\Enums\TrafficBlockLevel;
use App\Enums\TrafficBlockMethod;
use App\Enums\TrafficBlockNetworkState;
use App\Models\TrafficBlock;
use App\Models\TrafficSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrafficBlock>
 */
class TrafficBlockFactory extends Factory
{
    /** A zone marked blocked by hand. */
    public function definition(): array
    {
        return [
            'source_id' => TrafficSource::factory(),
            'level' => TrafficBlockLevel::Zone,
            'ref' => (string) fake()->unique()->numberBetween(1000, 999999),
            'method' => TrafficBlockMethod::Manual,
            'network_state' => TrafficBlockNetworkState::ByHand,
            'clicks_after' => 0,
            'blocked_at' => now(),
        ];
    }
}
