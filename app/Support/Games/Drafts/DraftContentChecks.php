<?php

namespace App\Support\Games\Drafts;

use App\Data\Games\ContentSetDraft;
use App\Enums\GameOptionSource;
use App\Enums\GameRoundMode;
use App\Enums\PostStatus;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Support\Games\Results\ResultProfileCoverage;
use App\Support\Games\Results\ResultProfileRange;
use App\Support\Games\Versus\ImageState;
use App\Support\Games\Versus\VersusOptionDraft;
use App\Support\Games\Versus\VersusRoundCheck;
use App\Support\Games\Versus\VersusRoundDraft;
use Illuminate\Support\Collection;

/**
 * The Content tab's checks of what is on screen, before it is saved: each
 * round's check, the result profiles' coverage and what keeps the set from
 * being published — the publish report's rules, read from the draft and the
 * posts and pictures it names. A picture chosen but not saved yet counts as
 * ready; once saved, its web sizes are what decide.
 */
final readonly class DraftContentChecks
{
    /**
     * @param  array<string, VersusRoundCheck>  $rounds  by round key
     * @param  list<string>  $profileIssues
     * @param  list<string>  $setIssues
     */
    private function __construct(
        public array $rounds,
        public int $scoredRounds,
        public array $profileIssues,
        public array $setIssues,
    ) {}

    /**
     * @param  Collection<int, Post>  $posts  by id
     * @param  Collection<int, MediaAsset>  $assets  by id
     * @param  list<string>  $pendingUploads  the places of pictures chosen but not saved
     */
    public static function of(ContentSetDraft $draft, Collection $posts, Collection $assets, array $pendingUploads): self
    {
        $checks = [];

        foreach ($draft->rounds as $round) {
            $options = [];

            foreach (array_values($round['options']) as $slot => $option) {
                $options[] = self::optionOf($option, $posts, $assets, in_array("round:{$round['key']}:{$slot}", $pendingUploads, true));
            }

            $checks[(string) $round['key']] = VersusRoundCheck::of(
                new VersusRoundDraft($round['question'] ?? null, GameRoundMode::tryFrom((string) $round['mode']) ?? GameRoundMode::Crowd, (bool) $round['unscored'], $options),
                (int) $draft->scoring['crowd_min_votes'],
                (int) $draft->scoring['crowd_min_gap'],
            );
        }

        $scored = count(array_filter($checks, fn (VersusRoundCheck $check): bool => $check->scored));
        $setIssues = match (true) {
            $checks === [] => ['The content set is empty. Add rounds in Content.'],
            $scored === 0 => ['Every round plays without points. Score at least one round.'],
            default => [],
        };

        $profiles = array_map(fn (array $profile): ResultProfileRange => new ResultProfileRange((string) $profile['name'], (int) $profile['min'], (int) $profile['max']), $draft->profiles);

        return new self($checks, $scored, $checks === [] ? [] : ResultProfileCoverage::issues($profiles, $scored), $setIssues);
    }

    /**
     * @param  array<string, mixed>  $option
     * @param  Collection<int, Post>  $posts
     * @param  Collection<int, MediaAsset>  $assets
     */
    private static function optionOf(array $option, Collection $posts, Collection $assets, bool $pending): VersusOptionDraft
    {
        $source = GameOptionSource::tryFrom((string) ($option['source'] ?? '')) ?? GameOptionSource::Post;

        if ($pending) {
            return new VersusOptionDraft(source: GameOptionSource::Upload, assetId: 0, image: ImageState::Ready, isCorrect: (bool) ($option['is_correct'] ?? false));
        }

        if ($source === GameOptionSource::Upload) {
            $asset = isset($option['asset_id']) ? $assets->get((int) $option['asset_id']) : null;

            return new VersusOptionDraft(
                source: $source,
                assetId: $asset?->id,
                image: DraftImageState::of($asset),
                isCorrect: (bool) ($option['is_correct'] ?? false),
            );
        }

        $post = isset($option['post_id']) ? $posts->get((int) $option['post_id']) : null;

        return new VersusOptionDraft(
            source: $source,
            postId: $post?->id,
            upvotes: $post === null ? 0 : (int) $post->upvotes_count,
            downvotes: $post === null ? 0 : (int) $post->downvotes_count,
            postPublic: $post !== null && ! $post->trashed() && $post->status === PostStatus::Published,
            assetId: $post?->image_asset_id,
            image: DraftImageState::of($post?->imageAsset),
            isCorrect: (bool) ($option['is_correct'] ?? false),
        );
    }

    /** How many rounds cannot be published as they stand. */
    public function roundsWithIssues(): int
    {
        return count(array_filter($this->rounds, fn (VersusRoundCheck $check): bool => ! $check->isPublishable()));
    }
}
