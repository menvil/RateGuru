<?php

namespace Database\Factories;

use App\Enums\GameRoundMode;
use App\Models\GameContentSet;
use App\Models\GameRound;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GameRound>
 */
class GameRoundFactory extends Factory
{
    /** A crowd round with its question and no options yet. */
    public function definition(): array
    {
        return [
            'content_set_id' => GameContentSet::factory(),
            'position' => 0,
            'internal_name' => fake()->words(3, true),
            'question' => 'Which one would you rather see in your feed?',
            'mode' => GameRoundMode::Crowd,
            'unscored' => false,
            'show_crowd' => true,
        ];
    }

    public function objective(): static
    {
        return $this->state(fn (): array => ['mode' => GameRoundMode::Objective]);
    }

    public function unscored(): static
    {
        return $this->state(fn (): array => ['unscored' => true]);
    }
}
