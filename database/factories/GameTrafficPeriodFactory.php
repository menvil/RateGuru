<?php

namespace Database\Factories;

use App\Enums\GameAudience;
use App\Models\Game;
use App\Models\GameTrafficPeriod;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GameTrafficPeriod>
 */
class GameTrafficPeriodFactory extends Factory
{
    /** An open period with no shares; attach versions to give it some. */
    public function definition(): array
    {
        return [
            'game_id' => Game::factory(),
            'audience' => GameAudience::Anonymous,
            'started_at' => now(),
            'ended_at' => null,
            'started_by_user_id' => User::factory()->admin(),
        ];
    }

    public function audience(GameAudience $audience): static
    {
        return $this->state(fn (): array => ['audience' => $audience]);
    }
}
