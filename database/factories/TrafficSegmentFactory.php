<?php

namespace Database\Factories;

use App\Models\TrafficSegment;
use App\Models\TrafficSource;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrafficSegment>
 */
class TrafficSegmentFactory extends Factory
{
    /** A segment of an ad network's mobile clicks, as an administrator saved it. */
    public function definition(): array
    {
        return [
            'source_id' => TrafficSource::factory(),
            'name' => 'Segment '.fake()->unique()->numberBetween(1, 99999),
            'rules' => [['parameter' => 'device', 'operator' => 'in', 'values' => ['mobile']]],
            'created_by_user_id' => User::factory()->admin(),
            'lock_version' => 1,
        ];
    }
}
