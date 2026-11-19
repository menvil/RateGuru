<?php

namespace Database\Factories;

use App\Enums\TrafficCampaignStatus;
use App\Models\TrafficCampaign;
use App\Models\TrafficSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrafficCampaign>
 */
class TrafficCampaignFactory extends Factory
{
    /** A campaign the first click brought, not set up yet. */
    public function definition(): array
    {
        return [
            'source_id' => TrafficSource::factory(),
            'external_id' => (string) fake()->unique()->numberBetween(100000, 9999999),
            'status' => TrafficCampaignStatus::New,
            'first_click_at' => now(),
            'archived_automatically' => false,
            'lock_version' => 1,
        ];
    }
}
