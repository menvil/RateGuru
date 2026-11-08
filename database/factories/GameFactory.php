<?php

namespace Database\Factories;

use App\Enums\GameMechanic;
use App\Enums\GameStatus;
use App\Models\Game;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Game>
 */
class GameFactory extends Factory
{
    /**
     * A Draft game an administrator has just made, with no content yet.
     * Production games are only ever made by CreateGameAction.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = Str::title(fake()->unique()->words(3, true));

        return [
            'slug' => Str::slug($title).'-'.fake()->unique()->numberBetween(1, 999_999),
            'internal_name' => $title,
            'title' => $title,
            'description' => fake()->sentence(),
            'mechanic' => GameMechanic::Versus,
            'status' => GameStatus::Draft,
            'created_by_user_id' => User::factory()->admin(),
            'updated_by_user_id' => fn (array $attributes) => $attributes['created_by_user_id'],
        ];
    }

    public function status(GameStatus $status): static
    {
        return $this->state(fn (): array => ['status' => $status]);
    }

    /** As a game with a published version is: slug and mechanic locked. */
    public function published(): static
    {
        return $this->state(fn (): array => ['first_published_at' => now()]);
    }
}
