<?php

namespace Database\Factories;

use App\Enums\TrafficChannel;
use App\Enums\TrafficDevice;
use App\Enums\TrafficSpendBasis;
use App\Models\TrafficPriceSet;
use App\Models\TrafficSource;
use App\Models\TrafficVisit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrafficVisit>
 */
class TrafficVisitFactory extends Factory
{
    /** A paid click of an ad network, on the price set in force, inside its 7-day window. */
    public function definition(): array
    {
        $now = now('UTC')->toImmutable();

        return [
            'channel' => TrafficChannel::Network,
            'source_id' => TrafficSource::factory(),
            'click_hash' => hash('sha256', fake()->unique()->uuid()),
            'visitor_hash' => hash('sha256', fake()->unique()->uuid()),
            'known' => false,
            'device' => TrafficDevice::Mobile,
            'price_set_id' => fn (): int => TrafficPriceSet::current()->id,
            'country_group' => 'rest',
            'worth_percent' => 100,
            'arrived_at' => $now,
            'network_day' => $now->format('Y-m-d'),
            'holds_until' => $now->addDays(7),
            'last_seen_at' => $now,
            'spend' => '0',
            'spend_basis' => TrafficSpendBasis::None,
        ];
    }

    /** An unpaid arrival through the given channel. */
    public function unpaid(TrafficChannel $channel = TrafficChannel::Direct): static
    {
        return $this->state(fn (): array => ['channel' => $channel, 'source_id' => null, 'click_hash' => null, 'holds_until' => null]);
    }
}
