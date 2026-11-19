<?php

namespace Database\Factories;

use App\Models\AccountBrowser;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccountBrowser>
 */
class AccountBrowserFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'visitor_hash' => hash('sha256', fake()->unique()->uuid()),
            'linked_at' => now(),
        ];
    }
}
