<?php

namespace Database\Factories;

use App\Models\TrafficBlock;
use App\Models\TrafficBlockListing;
use App\Models\TrafficCampaign;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrafficBlockListing>
 */
class TrafficBlockListingFactory extends Factory
{
    /** A block on a campaign's list, as last read. */
    public function definition(): array
    {
        return [
            'block_id' => TrafficBlock::factory(),
            'campaign_id' => TrafficCampaign::factory(),
            'listed' => true,
            'error' => null,
            'checked_at' => now(),
        ];
    }
}
