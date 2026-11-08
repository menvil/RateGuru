<?php

namespace Database\Factories;

use App\Enums\GameAttemptStatus;
use App\Enums\GameAudience;
use App\Models\GameAttempt;
use App\Models\GameVersion;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<GameAttempt>
 */
class GameAttemptFactory extends Factory
{
    /** A guest's attempt just started. */
    public function definition(): array
    {
        return [
            'public_id' => (string) Str::ulid(),
            'version_id' => GameVersion::factory(),
            'game_id' => fn (array $attributes) => GameVersion::query()->findOrFail($attributes['version_id'])->game_id,
            'audience' => GameAudience::Anonymous,
            'user_id' => null,
            'visitor_hash' => hash('sha256', Str::random(32)),
            'status' => GameAttemptStatus::Started,
            'rounds_answered' => 0,
            'max_score' => 1,
            'locale' => 'en',
            'started_at' => now(),
            'last_activity_at' => now(),
        ];
    }

    public function completed(int $matches = 1, ?string $profileKey = 'oracle'): static
    {
        return $this->state(fn (): array => [
            'status' => GameAttemptStatus::Completed,
            'rounds_answered' => 1,
            'matches' => $matches,
            'score' => $matches,
            'profile_key' => $profileKey,
            'completed_at' => now(),
        ]);
    }
}
