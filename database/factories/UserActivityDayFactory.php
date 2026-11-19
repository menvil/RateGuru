<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\UserActivityDay;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserActivityDay>
 */
class UserActivityDayFactory extends Factory
{
    /** Today, for a member who voted on it, the day after they signed up. */
    public function definition(): array
    {
        $now = now('UTC');

        return [
            'user_id' => User::factory(),
            'day' => $now->format('Y-m-d'),
            'day_number' => 1,
            'voted' => true,
            'commented' => false,
            'posted' => false,
            'played' => false,
            'first_at' => $now,
        ];
    }
}
