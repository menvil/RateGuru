<?php

namespace Database\Factories;

use App\Models\Game;
use App\Models\GameFlow;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GameFlow>
 */
class GameFlowFactory extends Factory
{
    /** A flow with no steps; add them with GameFlowStepFactory or a template. */
    public function definition(): array
    {
        return [
            'game_id' => Game::factory(),
            'name' => 'Result First',
            'position' => 0,
        ];
    }
}
