<?php

namespace Database\Factories;

use App\Actions\Moderation\MarkUserTrustedAction;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'username' => fake()->unique()->userName(),
            'email' => fake()->unique()->safeEmail(),
            'avatar_url' => null,
            'role' => UserRole::User,
            'status' => UserStatus::Active,
            'trust_level' => MarkUserTrustedAction::TRUSTED_LEVEL,
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function admin(): static
    {
        return $this->state(fn () => [
            'role' => UserRole::Admin,
        ]);
    }

    public function moderator(): static
    {
        return $this->state(fn () => [
            'role' => UserRole::Moderator,
        ]);
    }

    public function banned(): static
    {
        return $this->state(fn () => [
            'status' => UserStatus::Banned,
        ]);
    }

    public function trusted(): static
    {
        return $this->state(fn () => [
            'trust_level' => MarkUserTrustedAction::TRUSTED_LEVEL,
            'status' => UserStatus::Active,
        ]);
    }
}
