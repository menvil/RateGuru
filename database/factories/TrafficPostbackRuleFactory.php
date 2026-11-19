<?php

namespace Database\Factories;

use App\Enums\TrafficEvent;
use App\Models\TrafficPostbackRule;
use App\Models\TrafficSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrafficPostbackRule>
 */
class TrafficPostbackRuleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'source_id' => TrafficSource::factory(),
            'event' => TrafficEvent::Goal,
            'goal_code' => null,
            'url_template' => 'https://postback.example.com/conversion?click={click_id}&value={value}',
            'enabled' => true,
            'position' => 0,
        ];
    }
}
