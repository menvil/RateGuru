<?php

namespace Database\Factories;

use App\Models\Post;
use App\Models\RatingOption;
use App\Models\RatingVote;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RatingVote>
 */
class RatingVoteFactory extends Factory
{
    public function definition(): array
    {
        return [
            'post_id' => Post::factory()->published(),
            'user_id' => User::factory(),
            'rating_option_id' => RatingOption::factory(),
            'rating_group_id' => fn (array $attributes): int => RatingOption::query()
                ->findOrFail($attributes['rating_option_id'])
                ->rating_group_id,
        ];
    }
}
