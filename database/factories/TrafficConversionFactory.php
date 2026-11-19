<?php

namespace Database\Factories;

use App\Enums\TrafficEvent;
use App\Enums\TrafficValueBasis;
use App\Models\TrafficConversion;
use App\Models\TrafficVisit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrafficConversion>
 */
class TrafficConversionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'visit_id' => TrafficVisit::factory(),
            'event' => TrafficEvent::Started,
            'occurred_at' => now(),
            'source_id' => fn (array $attributes): int => (int) TrafficVisit::query()->findOrFail($attributes['visit_id'])->source_id,
            'value' => '0',
            'value_basis' => TrafficValueBasis::None,
        ];
    }
}
