<?php

namespace Database\Factories;

use App\Enums\VoteType;
use App\Models\Comment;
use App\Models\CommentVote;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommentVote>
 */
class CommentVoteFactory extends Factory
{
    public function definition(): array
    {
        return [
            'comment_id' => Comment::factory(),
            'user_id' => User::factory(),
            'type' => fake()->randomElement([VoteType::Up, VoteType::Down]),
        ];
    }
}
