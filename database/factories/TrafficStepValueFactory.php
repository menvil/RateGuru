<?php

namespace Database\Factories;

use App\Enums\TrafficStepValueMode;
use App\Models\Game;
use App\Models\TrafficStepValue;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrafficStepValue>
 */
class TrafficStepValueFactory extends Factory
{
    public function definition(): array
    {
        return [
            'game_id' => Game::factory(),
            'step' => 'completed',
            'value' => '0.05',
            'mode' => TrafficStepValueMode::Always,
            'set_by_user_id' => User::factory()->admin(),
        ];
    }
}
