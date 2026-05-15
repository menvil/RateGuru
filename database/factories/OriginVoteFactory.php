<?php

namespace Database\Factories;

use App\Enums\OriginType;
use App\Models\OriginVote;
use App\Models\Post;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OriginVote>
 */
class OriginVoteFactory extends Factory
{
    public function definition(): array
    {
        return [
            'post_id' => Post::factory(),
            'user_id' => User::factory(),
            'origin' => fake()->randomElement([OriginType::Homemade, OriginType::Restaurant]),
        ];
    }
}
