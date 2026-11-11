<?php

namespace App\Support\Games\Publishing;

use App\Enums\GameOptionSource;
use App\Models\Game;
use App\Models\GameFlow;
use App\Models\GameFlowStep;
use App\Models\GameResultProfile;
use App\Models\GameRound;
use App\Models\GameRoundOption;
use App\Support\Games\Drafts\ContentSetReport;
use App\Support\Games\Snapshot\GameVersionSnapshot;
use App\Support\Games\Snapshot\LocalizedText;
use App\Support\Games\Snapshot\SnapshotOption;
use App\Support\Games\Snapshot\SnapshotProfile;
use App\Support\Games\Snapshot\SnapshotRound;
use App\Support\Games\Snapshot\SnapshotStep;
use LogicException;

/**
 * Freezes a game's content set and flow into a version's snapshot: every text
 * with the translations it has now, every round with the crowd's votes as they
 * stand now and the option that earns its point, the profiles and the flow.
 * From then on the version plays from this alone.
 *
 * Built only from a set its report found publishable; the report's checks are
 * what decided each round's answer.
 */
final class VersionSnapshotBuilder
{
    public static function build(Game $game, ContentSetReport $content, GameFlow $flow): GameVersionSnapshot
    {
        if (! $content->isPublishable()) {
            throw new LogicException('Only a publishable content set can be frozen into a version.');
        }

        return self::assemble($game, $content, $flow);
    }

    /**
     * The same snapshot of a draft that is not publishable yet, for the admin
     * preview alone: a round whose point is not decided plays without one,
     * and nothing of it is ever stored.
     */
    public static function buildForPreview(Game $game, ContentSetReport $content, GameFlow $flow): GameVersionSnapshot
    {
        return self::assemble($game, $content, $flow);
    }

    private static function assemble(Game $game, ContentSetReport $content, GameFlow $flow): GameVersionSnapshot
    {
        $set = $content->set;
        $locales = [LocalizedText::REFERENCE => true];

        $text = function (?string $english, ?array $translations) use (&$locales): LocalizedText {
            $value = LocalizedText::fromColumns($english, $translations);

            foreach (array_keys($value->toArray()) as $locale) {
                $locales[$locale] = true;
            }

            return $value;
        };

        $rounds = $set->rounds->map(function (GameRound $round) use ($content, $text): SnapshotRound {
            $check = $content->rounds[(int) $round->id];

            return new SnapshotRound(
                key: 'r'.$round->id,
                question: $text($round->question, $round->question_translations),
                description: $text($round->description, $round->description_translations),
                reveal: $text($round->reveal_message, $round->reveal_message_translations),
                mode: $check->mode,
                scored: $check->scored,
                showCrowd: $round->show_crowd,
                answer: $check->answer ?? $check->decided,
                options: $round->options->map(function (GameRoundOption $option) use ($text): SnapshotOption {
                    $post = $option->source === GameOptionSource::Post ? $option->post : null;

                    return new SnapshotOption(
                        position: $option->position,
                        source: $option->source,
                        postId: $post?->id,
                        assetId: $post !== null ? $post->image_asset_id : $option->asset_id,
                        label: $text($option->label, $option->label_translations),
                        upvotes: $post === null ? 0 : (int) $post->upvotes_count,
                        downvotes: $post === null ? 0 : (int) $post->downvotes_count,
                    );
                })->values()->all(),
            );
        })->values()->all();

        $profiles = $set->profiles->map(fn (GameResultProfile $profile): SnapshotProfile => new SnapshotProfile(
            key: $profile->key,
            name: $text($profile->name, $profile->name_translations),
            description: $text($profile->description, $profile->description_translations),
            min: $profile->min_matches,
            max: $profile->max_matches,
            icon: $profile->icon,
            shareText: $text($profile->share_text, $profile->share_text_translations),
            shareAssetId: $profile->share_asset_id,
        ))->values()->all();

        $steps = $flow->steps->map(function (GameFlowStep $step) use ($text): SnapshotStep {
            $texts = [];

            foreach (array_keys($step->type->texts()) as $column) {
                /** @var array<string, string>|null $translations */
                $translations = $step->getAttribute($column.'_translations');
                $texts[$column] = $text($step->getAttribute($column), $translations);
            }

            return new SnapshotStep($step->key(), $step->type, $step->audience, $step->settings ?? [], $texts);
        })->values()->all();

        return new GameVersionSnapshot(
            gameId: (int) $game->id,
            slug: $game->slug,
            mechanic: $game->mechanic,
            title: $text($game->title, $game->title_translations),
            description: $text($game->description, $game->description_translations),
            coverAssetId: $game->cover_asset_id,
            pointsPerRound: $set->points_per_round,
            crowdMinVotes: $set->crowd_min_votes,
            crowdMinGap: $set->crowd_min_gap,
            rounds: $rounds,
            profiles: $profiles,
            flowId: (int) $flow->id,
            flowName: $flow->name,
            steps: $steps,
            locales: array_keys($locales),
        );
    }
}
