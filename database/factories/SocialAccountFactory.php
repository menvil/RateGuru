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

    /**
     * A link whose provider confirmed the address it records — the only shape
     * that survives somebody else claiming the account by proving that address.
     */
    public function verifiedEmail(string $email): static
    {
        return $this->state(fn (): array => [
            'provider_email' => $email,
            'provider_email_verified' => true,
        ]);
    }

    /** A link the provider explicitly did not vouch for. */
    public function unverifiedEmail(string $email): static
    {
        return $this->state(fn (): array => [
            'provider_email' => $email,
            'provider_email_verified' => false,
        ]);
    }

    /**
     * A row written before provider_email_verified existed: an address, and no
     * record either way of whether it was confirmed.
     */
    public function legacyUnknownVerification(string $email): static
    {
        return $this->state(fn (): array => [
            'provider_email' => $email,
            'provider_email_verified' => null,
        ]);
    }

    public function facebook(): static
    {
        return $this->state(fn () => ['provider' => SocialProvider::Facebook]);
    }
}
