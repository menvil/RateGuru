<?php

namespace App\Data\Games;

use App\Enums\GameOptionSource;
use App\Enums\GameRoundMode;
use App\Models\GameContentSet;
use App\Models\GameResultProfile;
use App\Models\GameRound;
use App\Models\GameRoundOption;

/**
 * A content set as the Content tab edits it, as plain arrays: its scoring,
 * its rounds in order with their two options, and its result profiles. Rows
 * carry their id once saved; a new one carries none. Each row has a `key`
 * that stays the same while it is edited, so an upload or an error can name
 * it before it has an id.
 *
 * Only the English texts are here: translations stay on the rows, for
 * Translation Center.
 */
final readonly class ContentSetDraft
{
    /**
     * @param  array{points_per_round: int, crowd_min_votes: int, crowd_min_gap: int}  $scoring
     * @param  list<array<string, mixed>>  $rounds
     * @param  list<array<string, mixed>>  $profiles
     */
    public function __construct(
        public array $scoring,
        public array $rounds,
        public array $profiles,
    ) {}

    public static function of(GameContentSet $set): self
    {
        return new self(
            scoring: [
                'points_per_round' => $set->points_per_round,
                'crowd_min_votes' => $set->crowd_min_votes,
                'crowd_min_gap' => $set->crowd_min_gap,
            ],
            rounds: $set->rounds->map(fn (GameRound $round): array => self::roundOf($round))->values()->all(),
            profiles: $set->profiles->map(fn (GameResultProfile $profile): array => self::profileOf($profile))->values()->all(),
        );
    }

    /** @return array<string, mixed> */
    public static function roundOf(GameRound $round): array
    {
        $options = $round->options->keyBy('position');

        return [
            'id' => (int) $round->id,
            'key' => 'r'.$round->id,
            'internal_name' => $round->internal_name,
            'question' => (string) $round->question,
            'description' => (string) $round->description,
            'reveal_message' => (string) $round->reveal_message,
            'mode' => $round->mode->value,
            'unscored' => $round->unscored,
            'show_crowd' => $round->show_crowd,
            'options' => [self::optionOf($options->get(0)), self::optionOf($options->get(1))],
        ];
    }

    /** @return array<string, mixed> */
    public static function optionOf(?GameRoundOption $option): array
    {
        return [
            'source' => $option?->source->value ?? GameOptionSource::Post->value,
            'post_id' => $option?->post_id,
            'asset_id' => $option?->asset_id,
            'label' => (string) $option?->label,
            'is_correct' => (bool) $option?->is_correct,
        ];
    }

    /** @return array<string, mixed> */
    public static function profileOf(GameResultProfile $profile): array
    {
        return [
            'id' => (int) $profile->id,
            'key' => $profile->key,
            'name' => $profile->name,
            'description' => (string) $profile->description,
            'min' => $profile->min_matches,
            'max' => $profile->max_matches,
            'icon' => $profile->icon,
            'share_text' => (string) $profile->share_text,
            'share_asset_id' => $profile->share_asset_id,
        ];
    }

    /** A new, empty round, as Add round makes it. */
    public static function newRound(string $key, int $number): array
    {
        return [
            'id' => null,
            'key' => $key,
            'internal_name' => 'Round '.$number,
            'question' => '',
            'description' => '',
            'reveal_message' => '',
            'mode' => GameRoundMode::Crowd->value,
            'unscored' => false,
            'show_crowd' => true,
            'options' => [self::optionOf(null), self::optionOf(null)],
        ];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['scoring' => $this->scoring, 'rounds' => $this->rounds, 'profiles' => $this->profiles];
    }
}
