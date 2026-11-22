<?php

namespace Database\Factories;

use App\Enums\TrafficDevice;
use App\Models\AccountAttribution;
use App\Models\GameAttempt;
use App\Models\TrafficVisit;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccountAttribution>
 */
class AccountAttributionFactory extends Factory
{
    /**
     * A new account, signed up and not confirmed yet, credited to nothing —
     * or to the visit and attempt given, what Retention reads of them copied
     * as RecordAccountSignupAction copies it, unless given too.
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'visitor_hash' => hash('sha256', fake()->unique()->uuid()),
            'signed_up_at' => now(),
            'confirmed_at' => null,
            'late' => false,
            'credited_visit_id' => null,
            'credited_attempt_id' => null,
            'credited_source_key' => fn (array $attributes): ?string => self::credited($attributes)['credited_source_key'],
            'credited_device' => fn (array $attributes): ?string => self::credited($attributes)['credited_device']?->value,
            'credited_game_id' => fn (array $attributes): ?int => self::credited($attributes)['credited_game_id'],
        ];
    }

    public function confirmed(): static
    {
        return $this->state(fn (): array => ['confirmed_at' => now()]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{credited_source_key: string|null, credited_device: TrafficDevice|null, credited_game_id: int|null}
     */
    private static function credited(array $attributes): array
    {
        $visit = $attributes['credited_visit_id'] ?? null;
        $attempt = $attributes['credited_attempt_id'] ?? null;

        return AccountAttribution::creditedKeys(
            is_numeric($visit) ? TrafficVisit::query()->find((int) $visit) : null,
            is_numeric($attempt) ? GameAttempt::query()->find((int) $attempt) : null,
        );
    }
}
