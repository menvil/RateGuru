<?php

namespace Database\Factories;

use App\Models\RatingGroup;
use App\Models\RatingOption;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<RatingOption>
 */
class RatingOptionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'rating_group_id' => RatingGroup::factory(),
            'key' => 'option_'.Str::lower((string) Str::ulid()),
            'label' => fake()->words(2, true),
            'description' => fake()->optional()->sentence(),
            'is_active' => true,
            'sort_order' => fake()->numberBetween(0, 100),
            'archived_at' => null,
        ];
    }
}
