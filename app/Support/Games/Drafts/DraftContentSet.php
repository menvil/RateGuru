<?php

namespace App\Support\Games\Drafts;

use App\Data\Games\ContentSetDraft;
use App\Enums\GameOptionSource;
use App\Enums\GameRoundMode;
use App\Models\GameContentSet;
use App\Models\GameResultProfile;
use App\Models\GameRound;
use App\Models\GameRoundOption;
use App\Models\MediaAsset;
use App\Models\Post;
use Illuminate\Database\Eloquent\Collection;

/**
 * A content set as an unsaved draft of it stands, for the Content tab's live
 * preview alone: the saved set with the draft's scoring, rounds, options and
 * profiles in place of its own, built in memory so ContentSetReport and the
 * preview's snapshot read it as they read a saved set. A row keeps its
 * translations while it keeps its id; a new one stands in with an id below
 * zero. Nothing of it is ever saved, and it queries nothing: the posts and
 * pictures come loaded.
 */
final class DraftContentSet
{
    /**
     * @param  Collection<int, Post>  $posts  by id
     * @param  Collection<int, MediaAsset>  $assets  by id
     */
    public static function of(GameContentSet $saved, ContentSetDraft $draft, Collection $posts, Collection $assets): GameContentSet
    {
        $savedRounds = $saved->rounds->keyBy('id');
        $savedProfiles = $saved->profiles->keyBy('id');
        $rounds = new Collection;
        $profiles = new Collection;

        foreach ($draft->rounds as $index => $row) {
            $id = self::id($row['id'] ?? null);
            $known = $id !== null ? $savedRounds->get($id) : null;
            $round = $known instanceof GameRound ? clone $known : new GameRound;
            $id = $known instanceof GameRound ? (int) $known->id : -($index + 1);
            $options = new Collection;

            foreach (self::rows($row['options'] ?? null) as $position => $data) {
                $source = GameOptionSource::tryFrom((string) ($data['source'] ?? '')) ?? GameOptionSource::Post;
                $postId = $source === GameOptionSource::Post ? self::id($data['post_id'] ?? null) : null;
                $assetId = $source === GameOptionSource::Upload ? self::id($data['asset_id'] ?? null) : null;
                $option = (new GameRoundOption)->forceFill([
                    'round_id' => $id,
                    'position' => $position,
                    'source' => $source,
                    'post_id' => $postId,
                    'asset_id' => $assetId,
                    'label' => (string) ($data['label'] ?? ''),
                    'label_translations' => $known?->options->firstWhere('position', $position)?->label_translations,
                    'is_correct' => (bool) ($data['is_correct'] ?? false),
                ]);
                $option->setRelation('post', $postId !== null ? $posts->get($postId) : null);
                $option->setRelation('asset', $assetId !== null ? $assets->get($assetId) : null);
                $options->push($option);
            }

            $round->forceFill([
                'id' => $id,
                'content_set_id' => $saved->id,
                'position' => $index,
                'internal_name' => (string) ($row['internal_name'] ?? ''),
                'question' => (string) ($row['question'] ?? ''),
                'description' => (string) ($row['description'] ?? ''),
                'reveal_message' => (string) ($row['reveal_message'] ?? ''),
                'mode' => GameRoundMode::tryFrom((string) ($row['mode'] ?? '')) ?? GameRoundMode::Crowd,
                'unscored' => (bool) ($row['unscored'] ?? false),
                'show_crowd' => (bool) ($row['show_crowd'] ?? true),
            ]);
            $round->setRelation('options', $options);
            $rounds->push($round);
        }

        foreach ($draft->profiles as $index => $row) {
            $id = self::id($row['id'] ?? null);
            $known = $id !== null ? $savedProfiles->get($id) : null;
            $profile = $known instanceof GameResultProfile ? clone $known : new GameResultProfile;
            $shareAssetId = self::id($row['share_asset_id'] ?? null);
            $profile->forceFill([
                'id' => $known instanceof GameResultProfile ? (int) $known->id : -($index + 1),
                'content_set_id' => $saved->id,
                'position' => $index,
                'key' => (string) ($row['key'] ?? 'p'.$index),
                'name' => (string) ($row['name'] ?? ''),
                'description' => (string) ($row['description'] ?? ''),
                'min_matches' => (int) ($row['min'] ?? 0),
                'max_matches' => (int) ($row['max'] ?? 0),
                'icon' => (string) ($row['icon'] ?? ''),
                'share_text' => (string) ($row['share_text'] ?? ''),
                'share_asset_id' => $shareAssetId,
            ]);
            $profile->setRelation('shareAsset', $shareAssetId !== null ? $assets->get($shareAssetId) : null);
            $profiles->push($profile);
        }

        $set = clone $saved;
        $set->forceFill([
            'points_per_round' => $draft->scoring['points_per_round'],
            'crowd_min_votes' => $draft->scoring['crowd_min_votes'],
            'crowd_min_gap' => $draft->scoring['crowd_min_gap'],
        ]);
        $set->setRelation('rounds', $rounds);
        $set->setRelation('profiles', $profiles);

        return $set;
    }

    /**
     * The posts the draft's options name.
     *
     * @return list<int>
     */
    public static function postIds(ContentSetDraft $draft): array
    {
        $ids = [];

        foreach ($draft->rounds as $round) {
            foreach (self::rows($round['options'] ?? null) as $option) {
                $ids[] = self::id($option['post_id'] ?? null);
            }
        }

        return array_values(array_unique(array_filter($ids)));
    }

    /**
     * The uploaded pictures the draft's options and profiles name.
     *
     * @return list<int>
     */
    public static function assetIds(ContentSetDraft $draft): array
    {
        $ids = [];

        foreach ($draft->rounds as $round) {
            foreach (self::rows($round['options'] ?? null) as $option) {
                $ids[] = self::id($option['asset_id'] ?? null);
            }
        }

        foreach ($draft->profiles as $profile) {
            $ids[] = self::id($profile['share_asset_id'] ?? null);
        }

        return array_values(array_unique(array_filter($ids)));
    }

    /** @return list<array<string, mixed>> */
    private static function rows(mixed $rows): array
    {
        return is_array($rows) ? array_values(array_filter($rows, is_array(...))) : [];
    }

    private static function id(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }
}
