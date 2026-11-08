<?php

namespace App\Support\Games\Drafts;

use App\Enums\GameOptionSource;
use App\Enums\PostStatus;
use App\Models\GameContentSet;
use App\Models\GameResultProfile;
use App\Models\GameRound;
use App\Models\GameRoundOption;
use App\Support\Games\Results\ResultProfileCoverage;
use App\Support\Games\Results\ResultProfileRange;
use App\Support\Games\Versus\VersusOptionDraft;
use App\Support\Games\Versus\VersusRoundCheck;
use App\Support\Games\Versus\VersusRoundDraft;

/**
 * What a content set's draft says about itself right now: each round's check
 * against the site as it is, whether its profiles cover every result, and
 * what keeps it from being published. Read from a set loaded by
 * GameDraftQuery; it queries nothing itself.
 */
final readonly class ContentSetReport
{
    /**
     * @param  array<int, VersusRoundCheck>  $rounds  by round id
     * @param  list<string>  $profileIssues
     * @param  list<string>  $setIssues
     */
    private function __construct(
        public GameContentSet $set,
        public array $rounds,
        public int $scoredRounds,
        public array $profileIssues,
        public array $setIssues,
    ) {}

    public static function of(GameContentSet $set): self
    {
        $checks = [];

        foreach ($set->rounds as $round) {
            $checks[(int) $round->id] = VersusRoundCheck::of(self::draftOf($round), $set->crowd_min_votes, $set->crowd_min_gap);
        }

        $scored = count(array_filter($checks, fn (VersusRoundCheck $check): bool => $check->scored));
        $setIssues = [];

        if ($checks === []) {
            $setIssues[] = 'The content set is empty. Add rounds in Content.';
        } elseif ($scored === 0) {
            $setIssues[] = 'Every round plays without points. Score at least one round.';
        }

        $profiles = $set->profiles->map(fn (GameResultProfile $profile): ResultProfileRange => new ResultProfileRange($profile->name, $profile->min_matches, $profile->max_matches))->all();

        return new self($set, $checks, $scored, $checks === [] ? [] : ResultProfileCoverage::issues(array_values($profiles), $scored), $setIssues);
    }

    /** A round's draft as the versus checks read it. */
    public static function draftOf(GameRound $round): VersusRoundDraft
    {
        return new VersusRoundDraft(
            question: $round->question,
            mode: $round->mode,
            unscored: $round->unscored,
            options: $round->options->map(fn (GameRoundOption $option): VersusOptionDraft => self::optionOf($option))->values()->all(),
        );
    }

    public static function optionOf(GameRoundOption $option): VersusOptionDraft
    {
        $post = $option->source === GameOptionSource::Post ? $option->post : null;

        return new VersusOptionDraft(
            source: $option->source,
            postId: $post?->id,
            upvotes: $post === null ? 0 : (int) $post->upvotes_count,
            downvotes: $post === null ? 0 : (int) $post->downvotes_count,
            postPublic: $post !== null && ! $post->trashed() && $post->status === PostStatus::Published,
            assetId: $option->source === GameOptionSource::Upload ? $option->asset_id : $post?->image_asset_id,
            image: DraftImageState::of($option->source === GameOptionSource::Upload ? $option->asset : $post?->imageAsset),
            isCorrect: $option->is_correct,
            label: $option->label,
        );
    }

    /** The rounds that cannot be published as they are. */
    public function roundsWithIssues(): int
    {
        return count(array_filter($this->rounds, fn (VersusRoundCheck $check): bool => ! $check->isPublishable()));
    }

    /** Everything that keeps the set from being published, for the publish report. */
    public function isPublishable(): bool
    {
        return $this->setIssues === [] && $this->roundsWithIssues() === 0 && $this->profileIssues === [];
    }

    /**
     * Profiles without a share image, by name: shown as a warning, never a block.
     *
     * @return list<string>
     */
    public function profilesWithoutShareImage(): array
    {
        return $this->set->profiles
            ->filter(fn (GameResultProfile $profile): bool => $profile->share_asset_id === null)
            ->map(fn (GameResultProfile $profile): string => $profile->name)
            ->values()
            ->all();
    }
}
