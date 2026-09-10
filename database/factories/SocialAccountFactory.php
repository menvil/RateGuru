<?php

namespace Database\Factories;

use App\Enums\SocialProvider;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SocialAccount>
 */
class SocialAccountFactory extends Factory
{
    protected $model = SocialAccount::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'provider' => SocialProvider::Google,
            'provider_user_id' => fake()->unique()->numerify('####################'),
        ];
    }

    public function google(): static
    {
        return $this->state(fn () => ['provider' => SocialProvider::Google]);
    }

    public function facebook(): static
    {
        return $this->state(fn () => ['provider' => SocialProvider::Facebook]);
    }
}
