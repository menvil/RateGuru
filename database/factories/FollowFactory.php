<?php

namespace Database\Factories;

use App\Models\Follow;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Follow>
 */
class FollowFactory extends Factory
{
    protected $model = Follow::class;

    public function definition(): array
    {
        return [
            'follower_id' => User::factory(),
            'author_id' => User::factory(),
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Follow $follow) {
            if ((int) $follow->follower_id === (int) $follow->author_id) {
                throw new \InvalidArgumentException(
                    'FollowFactory produced a self-follow record (follower_id === author_id). Pass distinct user IDs.'
                );
            }
        });
    }
}
