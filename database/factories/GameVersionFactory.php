<?php

namespace Database\Factories;

use App\Enums\GameVersionStatus;
use App\Models\Game;
use App\Models\GameContentSet;
use App\Models\GameFlow;
use App\Models\GameVersion;
use App\Models\User;
use App\Support\Games\Snapshot\GameVersionSnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GameVersion>
 */
class GameVersionFactory extends Factory
{
    /**
     * A published version with a one-round snapshot: two posts the crowd
     * decided for the first, and one profile per outcome. Tests about what a
     * version holds publish one through PublishGameVersionAction instead.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'game_id' => Game::factory()->published(),
            'number' => 1,
            'status' => GameVersionStatus::Active,
            'content_set_id' => fn (array $attributes) => GameContentSet::factory()->state(['game_id' => $attributes['game_id']]),
            'flow_id' => fn (array $attributes) => GameFlow::factory()->state(['game_id' => $attributes['game_id']]),
            'snapshot' => fn (array $attributes): array => self::snapshotFor((int) $attributes['game_id'], (int) $attributes['flow_id']),
            'snapshot_schema' => GameVersionSnapshot::SCHEMA,
            'round_count' => 1,
            'scored_round_count' => 1,
            'max_score' => 1,
            'published_by_user_id' => User::factory()->admin(),
            'published_at' => now(),
        ];
    }

    public function retired(): static
    {
        return $this->state(fn (): array => ['status' => GameVersionStatus::Retired, 'retired_at' => now()]);
    }

    /** @return array<string, mixed> */
    public static function snapshotFor(int $gameId, int $flowId, ?int $postA = null, ?int $postB = null): array
    {
        $option = fn (int $position, ?int $postId): array => [
            'position' => $position,
            'source' => 'post',
            'post_id' => $postId ?? 900_000 + $position,
            'asset_id' => null,
            'label' => [],
            'upvotes' => $position === 0 ? 920 : 780,
            'downvotes' => $position === 0 ? 80 : 220,
        ];

        return [
            'schema' => GameVersionSnapshot::SCHEMA,
            'game' => ['id' => $gameId, 'slug' => 'game-'.$gameId, 'mechanic' => 'versus', 'title' => ['en' => 'Trust Your Taste'], 'description' => [], 'cover_asset_id' => null],
            'scoring' => ['points_per_round' => 1, 'crowd_min_votes' => 10, 'crowd_min_gap' => 0],
            'rounds' => [[
                'key' => 'r1',
                'question' => ['en' => 'Which one would you rather see in your feed?'],
                'description' => [],
                'reveal' => [],
                'mode' => 'crowd',
                'scored' => true,
                'show_crowd' => true,
                'answer' => 0,
                'options' => [$option(0, $postA), $option(1, $postB)],
            ]],
            'profiles' => [
                ['key' => 'independent', 'name' => ['en' => 'Independent'], 'description' => ['en' => 'You have your own taste.'], 'min' => 0, 'max' => 0, 'icon' => 'compass', 'share_text' => ['en' => 'I got {profile} in {game}.'], 'share_asset_id' => null],
                ['key' => 'oracle', 'name' => ['en' => 'Oracle'], 'description' => ['en' => 'You matched every choice.'], 'min' => 1, 'max' => 1, 'icon' => 'sparkles', 'share_text' => ['en' => 'I got {profile} in {game}.'], 'share_asset_id' => null],
            ],
            'flow' => ['id' => $flowId, 'name' => 'Result First', 'steps' => [
                ['key' => 's1', 'type' => 'landing', 'audience' => 'all', 'settings' => ['show_branding' => true], 'texts' => ['title' => ['en' => 'Which one would you pick?'], 'button_label' => ['en' => 'Start playing']]],
                ['key' => 's2', 'type' => 'game', 'audience' => 'all', 'settings' => ['advance' => 'button', 'advance_seconds' => 2.5], 'texts' => []],
                ['key' => 's3', 'type' => 'full_result', 'audience' => 'all', 'settings' => ['show_score' => true, 'show_profile' => true, 'share_enabled' => true, 'signup' => 'inline'], 'texts' => ['title' => ['en' => 'Keep playing']]],
            ]],
            'locales' => ['en'],
        ];
    }
}
