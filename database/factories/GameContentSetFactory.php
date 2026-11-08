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
            'show_agreement' => true,
            'crowd_min_votes' => 100,
            'crowd_min_gap' => 5,
        ];
    }

    public function archived(): static
    {
        return $this->state(fn (): array => ['archived_at' => now()]);
    }
}
