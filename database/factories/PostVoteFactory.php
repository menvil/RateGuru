<?php

namespace Database\Factories;

use App\Enums\VoteType;
use App\Models\Post;
use App\Models\PostVote;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PostVote>
 */
class PostVoteFactory extends Factory
{
    public function definition(): array
    {
        return [
            'post_id' => Post::factory(),
            'user_id' => User::factory(),
            'type' => fake()->randomElement([VoteType::Up, VoteType::Down]),
        ];
    }
}
