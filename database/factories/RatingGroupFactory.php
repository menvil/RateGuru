<?php

namespace Database\Factories;

use App\Models\RatingGroup;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<RatingGroup>
 */
class RatingGroupFactory extends Factory
{
    public function definition(): array
    {
        $key = 'group_'.Str::lower((string) Str::ulid());

        return [
            'key' => $key,
            'label' => fake()->words(2, true),
            'description' => fake()->optional()->sentence(),
            'min_options' => 2,
            'max_options' => 10,
            'is_active' => true,
            'sort_order' => fake()->numberBetween(0, 100),
        ];
    }
}
