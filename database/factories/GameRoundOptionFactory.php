<?php

namespace Database\Factories;

use App\Enums\GameOptionSource;
use App\Models\GameRound;
use App\Models\GameRoundOption;
use App\Models\MediaAsset;
use App\Models\Post;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GameRoundOption>
 */
class GameRoundOptionFactory extends Factory
{
    /** An option showing a published post's picture. */
    public function definition(): array
    {
        return [
            'round_id' => GameRound::factory(),
            'position' => 0,
            'source' => GameOptionSource::Post,
            'post_id' => Post::factory()->published()->withImage(),
            'asset_id' => null,
            'label' => null,
            'is_correct' => false,
        ];
    }

    /** Showing a post with these site votes. */
    public function votes(int $up, int $down): static
    {
        return $this->state(fn (): array => [
            'post_id' => Post::factory()->published()->withImage()->state(['upvotes_count' => $up, 'downvotes_count' => $down]),
        ]);
    }

    /** Showing an uploaded picture instead of a post's. */
    public function upload(bool $correct = false): static
    {
        return $this->state(fn (): array => [
            'source' => GameOptionSource::Upload,
            'post_id' => null,
            'asset_id' => MediaAsset::factory()->postImage(),
            'is_correct' => $correct,
        ]);
    }
}
