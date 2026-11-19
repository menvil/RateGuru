<?php

namespace Database\Factories;

use App\Enums\TrafficEvent;
use App\Enums\TrafficPostbackStatus;
use App\Models\TrafficConversion;
use App\Models\TrafficPostback;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrafficPostback>
 */
class TrafficPostbackFactory extends Factory
{
    public function definition(): array
    {
        return [
            'conversion_id' => TrafficConversion::factory(),
            'rule_id' => null,
            'source_id' => fn (array $attributes): int => (int) TrafficConversion::query()->findOrFail($attributes['conversion_id'])->source_id,
            'event' => fn (array $attributes): TrafficEvent => TrafficConversion::query()->findOrFail($attributes['conversion_id'])->event,
            'url' => 'https://postback.example.com/conversion?click={click_id}',
            'status' => TrafficPostbackStatus::Pending,
            'attempts' => 0,
            'run_after' => now(),
            'expires_at' => now()->addDay(),
            'created_at' => now(),
        ];
    }
}
