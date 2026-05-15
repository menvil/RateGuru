<?php

namespace Database\Factories;

use App\Enums\CuisineType;
use App\Models\CuisineVote;
use App\Models\Post;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CuisineVote>
 */
class CuisineVoteFactory extends Factory
{
    public function definition(): array
    {
        return [
            'post_id' => Post::factory(),
            'user_id' => User::factory(),
            'cuisine' => fake()->randomElement([
                CuisineType::Italian,
                CuisineType::Asian,
                CuisineType::American,
                CuisineType::Mexican,
                CuisineType::Other,
            ]),
        ];
    }
}
