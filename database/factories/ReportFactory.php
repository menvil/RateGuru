<?php

namespace Database\Factories;

use App\Enums\ReportReason;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Report>
 */
class ReportFactory extends Factory
{
    public function definition(): array
    {
        return [
            'reporter_id' => User::factory(),
            'target_type' => Post::class,
            'target_id' => Post::factory(),
            'reason' => fake()->randomElement(ReportReason::cases()),
            'message' => fake()->optional()->sentence(),
            'status' => 'open',
            'resolved_by' => null,
            'resolved_at' => null,
        ];
    }

    public function forPost(): static
    {
        return $this->state(fn () => [
            'target_type' => Post::class,
            'target_id' => Post::factory(),
        ]);
    }

    public function resolved(): static
    {
        return $this->state(fn () => [
            'status' => 'resolved',
            'resolved_by' => User::factory(),
            'resolved_at' => now(),
        ]);
    }
}
