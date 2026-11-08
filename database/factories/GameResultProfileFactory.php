<?php

namespace Database\Factories;

use App\Models\GameContentSet;
use App\Models\GameResultProfile;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<GameResultProfile>
 */
class GameResultProfileFactory extends Factory
{
    public function definition(): array
    {
        $name = Str::title(fake()->unique()->word());

        return [
            'content_set_id' => GameContentSet::factory(),
            'position' => 0,
            'key' => Str::slug($name),
            'name' => $name,
            'description' => fake()->sentence(),
            'min_matches' => 0,
            'max_matches' => 0,
            'icon' => 'award',
            'share_text' => 'I got {profile} in {game}.',
        ];
    }

    public function range(int $min, int $max): static
    {
        return $this->state(fn (): array => ['min_matches' => $min, 'max_matches' => $max]);
    }
}
