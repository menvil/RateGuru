<?php

namespace Database\Factories;

use App\Models\AccountAttribution;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccountAttribution>
 */
class AccountAttributionFactory extends Factory
{
    /** A new account, signed up and not confirmed yet, credited to nothing. */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'visitor_hash' => hash('sha256', fake()->unique()->uuid()),
            'signed_up_at' => now(),
            'confirmed_at' => null,
            'late' => false,
        ];
    }

    public function confirmed(): static
    {
        return $this->state(fn (): array => ['confirmed_at' => now()]);
    }
}
