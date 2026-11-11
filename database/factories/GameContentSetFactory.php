<?php

namespace Database\Factories;

use App\Models\Game;
use App\Models\GameContentSet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GameContentSet>
 */
class GameContentSetFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'game_id' => Game::factory(),
            'name' => 'Standard',
            'position' => 0,
            'points_per_round' => 1,
            'crowd_min_votes' => 10,
            'crowd_min_gap' => 0,
        ];
    }

    public function archived(): static
    {
        return $this->state(fn (): array => ['archived_at' => now()]);
    }
}
