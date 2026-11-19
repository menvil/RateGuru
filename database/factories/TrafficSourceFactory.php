<?php

namespace Database\Factories;

use App\Enums\TrafficBlockLevel;
use App\Enums\TrafficCostUnit;
use App\Enums\TrafficSourceStatus;
use App\Enums\TrafficSourceType;
use App\Enums\TrafficSpendMode;
use App\Models\TrafficSource;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrafficSource>
 */
class TrafficSourceFactory extends Factory
{
    /** An active ad network whose links carry a click id, a campaign, a site, a zone and the click's price. */
    public function definition(): array
    {
        return [
            'type' => TrafficSourceType::Network,
            'name' => 'Network '.fake()->unique()->numberBetween(1, 99999),
            'link_key' => 'net'.fake()->unique()->numberBetween(1, 999999),
            'preset_key' => null,
            'connector' => null,
            'status' => TrafficSourceStatus::Active,
            'attribution_days' => 7,
            'click_id_ttl_hours' => null,
            'target_cpa' => null,
            'reporting_timezone' => 'UTC',
            'link_params' => [
                ['kind' => 'click', 'label' => 'Click ID', 'param' => 'cid', 'token' => '{click_id}', 'note' => ''],
                ['kind' => 'campaign', 'label' => 'Campaign', 'param' => 'campaign', 'token' => '{campaign_id}', 'note' => ''],
                ['kind' => 'site', 'label' => 'Site', 'param' => 'site', 'token' => '{site_id}', 'note' => ''],
                ['kind' => 'zone', 'label' => 'Zone', 'param' => 'zone', 'token' => '{zone_id}', 'note' => ''],
                ['kind' => 'cost', 'label' => 'Cost', 'param' => 'cost', 'token' => '{cost}', 'note' => ''],
            ],
            'response_rules' => null,
            'spend_mode' => TrafficSpendMode::ClickCost,
            'spend_estimate' => null,
            'cost_unit' => TrafficCostUnit::Click,
            'rate_unit' => TrafficCostUnit::Click,
            'block_level' => TrafficBlockLevel::Zone,
            'block_auto' => false,
            'block_daily_cap' => 10,
            'created_by_user_id' => User::factory()->admin(),
            'lock_version' => 1,
        ];
    }

    /** A banner or link bought at a fixed price: no click id, no postbacks. */
    public function placement(): static
    {
        return $this->state(fn (): array => [
            'type' => TrafficSourceType::Placement,
            'link_key' => 'banner'.fake()->unique()->numberBetween(1, 999999),
            'link_params' => [],
            'spend_mode' => TrafficSpendMode::Fixed,
            'fixed_amount' => '100.00',
            'fixed_period' => 'month',
            'block_level' => TrafficBlockLevel::Site,
            'creatives' => ['main'],
        ]);
    }

    public function status(TrafficSourceStatus $status): static
    {
        return $this->state(fn (): array => ['status' => $status]);
    }
}
