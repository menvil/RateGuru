<?php

namespace App\Support\Games\Publishing;

use App\Enums\GameAdvanceMode;
use App\Enums\GameFlowStepType;
use App\Enums\GameOnward;
use App\Enums\GameOptionSource;
use App\Enums\GamePageDestination;
use App\Enums\GamePostTarget;
use App\Enums\GameRoundMode;
use App\Enums\GameSignupMode;
use App\Support\Games\Flow\GameFlowStepSettings;
use App\Support\Games\Snapshot\GameVersionSnapshot;
use App\Support\Games\Snapshot\LocalizedText;
use App\Support\Games\Snapshot\SnapshotOption;
use App\Support\Games\Snapshot\SnapshotProfile;
use App\Support\Games\Snapshot\SnapshotRound;
use App\Support\Games\Snapshot\SnapshotStep;
use App\Support\Games\Versus\VersusRoundCheck;

/**
 * What players would meet differently if a draft were published now, against
 * the version they play: the two snapshots compared part by part, each change
 * named as the editor that makes it names it — “Full Result · Way-on title”,
 * “Round 2 · Question”, “Step order”.
 *
 * Only what players meet counts. The crowd's votes, and the crowd's pick they
 * decide, move by themselves and are not a change; nor are names only the
 * admin sees — a flow's, a set's, a round's. A text counts once: by its
 * English, or, when only its translations differ, marked “· translations”.
 * Settings compare as GameFlowStepSettings normalises them, so a setting
 * stored before it had a default reads as that default.
 *
 * A step setting or text the step's own choices leave out of the Flow editor
 * is left out here too: the editor hides it because players never meet it —
 * the way-on texts of a Full Result that goes on to the next step, the post
 * of one that goes on to a page. A setting counts only where the editor shows
 * it in both the version and the draft; a change of the choice that hides or
 * shows it — Way on, Where it leads — is named instead.
 */
final class VersionChanges
{
    /** The Flow editor's label for each step setting; the keys one control holds share its label. */
    public const SETTING_LABELS = [
        'show_branding' => 'Show the site’s name',
        'advance' => 'Next pair',
        'advance_seconds' => 'Delay before the next pair',
        'show_partial' => 'Show partial score',
        'required' => 'Required to continue',
        'skip_known' => 'Don’t ask again on a replay',
        'consent_enabled' => 'Marketing consent checkbox',
        'show_score' => 'Show score',
        'show_profile' => 'Show profile',
        'share_enabled' => 'Share buttons',
        'onward' => 'Way on',
        'signup' => 'Sign-up for anonymous visitors',
        'feed_sort' => 'Which feed',
        'post_target' => 'Which post',
        'post_id' => 'Post',
        'post_author' => 'On the post\'s card',
        'post_likes' => 'On the post\'s card',
        'post_votes' => 'On the post\'s card',
        'post_comments' => 'On the post\'s card',
        'ask_vote' => 'Ask on the post',
        'ask_groups' => 'Ask on the post',
        'auto_open' => 'Open the post by itself',
        'destination' => 'Where it leads',
        'category' => 'Category',
        'tag' => 'Tag',
        'path' => 'Page address',
        'url' => 'Site address',
        'new_tab' => 'Open in a new tab',
        'next_game_id' => 'Next game',
    ];

    /**
     * The game's own texts and cover, as the General tab names them.
     *
     * @return list<string>
     */
    public static function game(GameVersionSnapshot $published, GameVersionSnapshot $draft): array
    {
        return self::gameAgainst($published, $draft->title, $draft->description, $draft->coverAssetId);
    }

    /**
     * The same, against the game's own title, description and cover as they
     * stand — the values a snapshot would freeze, read without freezing the
     * whole draft.
     *
     * @return list<string>
     */
    public static function gameAgainst(GameVersionSnapshot $published, LocalizedText $title, LocalizedText $description, ?int $coverAssetId): array
    {
        return array_values(array_filter([
            self::text('Public title', $published->title, $title),
            self::text('Description', $published->description, $description),
            $published->coverAssetId !== $coverAssetId ? 'Cover image' : null,
        ]));
    }

    /**
     * The flow: its goal, its steps added, removed or put in another order,
     * and each step's audience, settings and texts.
     *
     * @return list<string>
     */
    public static function flow(GameVersionSnapshot $published, GameVersionSnapshot $draft): array
    {
        $changes = [];

        // A first-shape snapshot froze no goal: there is nothing to compare it with.
        if ($published->flowGoal !== null && $draft->flowGoal !== null && $published->flowGoal !== $draft->flowGoal) {
            $changes[] = 'Goal';
        }

        $before = self::byKey($published->steps);
        $after = self::byKey($draft->steps);
        // A step whose kind changed is another step: the old one gone, the new one added.
        $kept = self::keys(array_filter($after, fn (SnapshotStep $step): bool => ($before[$step->key] ?? null)?->type === $step->type));

        if (self::reordered(self::keys($before), $kept)) {
            $changes[] = 'Step order';
        }

        $draftNames = self::stepNames($draft->steps);
        $publishedNames = self::stepNames($published->steps);

        foreach ($draft->steps as $step) {
            if (! in_array($step->key, $kept, true)) {
                $changes[] = $draftNames[$step->key].' added';

                continue;
            }

            // A kept step named by another place than the version's says where it was, so no name means two steps.
            $name = $draftNames[$step->key] !== $publishedNames[$step->key] && str_ends_with($draftNames[$step->key], ')')
                ? $draftNames[$step->key].' (was step '.(array_search($step->key, self::keys($before), true) + 1).')'
                : $draftNames[$step->key];

            foreach (self::step($before[$step->key], $step) as $what) {
                $changes[] = $name.' · '.$what;
            }
        }

        foreach ($published->steps as $step) {
            if (! in_array($step->key, $kept, true)) {
                $changes[] = $publishedNames[$step->key].' removed';
            }
        }

        return $changes;
    }

    /**
     * The content set: its rounds added, removed or put in another order and
     * what each says and shows, its scoring, and its result profiles.
     *
     * @return list<string>
     */
    public static function content(GameVersionSnapshot $published, GameVersionSnapshot $draft): array
    {
        $changes = [];
        $before = self::byKey($published->rounds);
        $after = self::byKey($draft->rounds);
        $kept = self::keys(array_intersect_key($after, $before));

        if (self::reordered(self::keys($before), $kept)) {
            $changes[] = 'Round order';
        }

        $was = array_flip(self::keys($before));

        foreach ($draft->rounds as $index => $round) {
            $name = 'Round '.($index + 1);

            // A kept round at another place says where it was: removed rounds are named by the version's places.
            if (isset($before[$round->key]) && $was[$round->key] !== $index) {
                $name .= ' (was Round '.($was[$round->key] + 1).')';
            }

            if (! isset($before[$round->key])) {
                $changes[] = $name.' added';

                continue;
            }

            foreach (self::round($before[$round->key], $round) as $what) {
                $changes[] = $name.' · '.$what;
            }
        }

        foreach ($published->rounds as $index => $round) {
            if (! isset($after[$round->key])) {
                $changes[] = 'Round '.($index + 1).' removed';
            }
        }

        foreach ([
            'Points per match' => [$published->pointsPerRound, $draft->pointsPerRound],
            'Site votes each post needs' => [$published->crowdMinVotes, $draft->crowdMinVotes],
            'Gap the crowd pick needs' => [$published->crowdMinGap, $draft->crowdMinGap],
        ] as $label => [$was, $is]) {
            if ($was !== $is) {
                $changes[] = 'Scoring · '.$label;
            }
        }

        $profilesBefore = self::byKey($published->profiles);
        $profilesAfter = self::byKey($draft->profiles);

        foreach ($draft->profiles as $profile) {
            $old = $profilesBefore[$profile->key] ?? null;

            if ($old === null) {
                $changes[] = self::profileName($profile).' added';

                continue;
            }

            foreach (self::profile($old, $profile) as $what) {
                $changes[] = self::profileName($profile).' · '.$what;
            }
        }

        foreach ($published->profiles as $profile) {
            if (! isset($profilesAfter[$profile->key])) {
                $changes[] = self::profileName($profile).' removed';
            }
        }

        return $changes;
    }

    /**
     * What changed in one step kept from the version to the draft, in the
     * order its panel shows it.
     *
     * @return list<string>
     */
    private static function step(SnapshotStep $published, SnapshotStep $draft): array
    {
        $changes = [];

        if ($draft->type->fixedAudience() === null && $published->audience !== $draft->audience) {
            $changes[] = 'Show to';
        }

        $was = GameFlowStepSettings::normalize($published->type, $published->settings);
        $is = GameFlowStepSettings::normalize($draft->type, $draft->settings);

        foreach (array_intersect(self::shownSettings($draft->type, $is), self::shownSettings($published->type, $was)) as $key) {
            // The rating groups a post asks are a set: the same groups in another order ask the same.
            $same = $key === 'ask_groups' ? self::groupSet($was[$key]) === self::groupSet($is[$key]) : $was[$key] === $is[$key];

            if (! $same) {
                $changes[] = self::SETTING_LABELS[$key];
            }
        }

        $empty = LocalizedText::of([]);

        foreach (array_intersect(self::shownTexts($draft->type, $is), self::shownTexts($published->type, $was)) as $column) {
            $changes[] = self::text($draft->type->texts()[$column]['label'], $published->texts[$column] ?? $empty, $draft->texts[$column] ?? $empty);
        }

        return array_values(array_unique(array_filter($changes)));
    }

    /**
     * The settings the Flow editor shows for a step with these settings, in
     * its order (filament.pages.games.flow.settings).
     *
     * @param  array<string, bool|int|float|string|null>  $settings  normalised
     * @return list<string>
     */
    private static function shownSettings(GameFlowStepType $type, array $settings): array
    {
        return match ($type) {
            GameFlowStepType::Landing => ['show_branding'],
            GameFlowStepType::Game => ['advance', ...($settings['advance'] === GameAdvanceMode::Auto->value ? ['advance_seconds'] : [])],
            GameFlowStepType::ResultPreview => ['show_partial'],
            GameFlowStepType::EmailCapture => ['required', 'skip_known', 'consent_enabled'],
            GameFlowStepType::FullResult => ['show_score', 'show_profile', 'share_enabled', 'onward', ...match ($settings['onward']) {
                GameOnward::Post->value => [...self::postSettings($settings), 'auto_open'],
                GameOnward::Page->value => self::pageSettings($settings),
                GameOnward::Feed->value => ['feed_sort'],
                default => ['signup'],
            }],
            GameFlowStepType::Feed => ['feed_sort'],
            GameFlowStepType::GoToPost => [...self::postSettings($settings), 'auto_open'],
            GameFlowStepType::GoToPage => self::pageSettings($settings),
            GameFlowStepType::NextGame => ['next_game_id'],
            default => [],
        };
    }

    /**
     * @param  array<string, bool|int|float|string|null>  $settings
     * @return list<string>
     */
    private static function postSettings(array $settings): array
    {
        return [
            'post_target',
            ...($settings['post_target'] === GamePostTarget::Chosen->value ? ['post_id'] : []),
            'post_author', 'post_likes', 'post_votes', 'post_comments', 'ask_vote', 'ask_groups',
        ];
    }

    /**
     * @param  array<string, bool|int|float|string|null>  $settings
     * @return list<string>
     */
    private static function pageSettings(array $settings): array
    {
        return match ($settings['destination']) {
            GamePageDestination::Tag->value => ['destination', 'tag'],
            GamePageDestination::Page->value => ['destination', 'path'],
            GamePageDestination::Url->value => ['destination', 'url', 'new_tab'],
            default => ['destination', 'category'],
        };
    }

    /**
     * The texts the Flow editor shows for a step with these settings: all
     * its kind has, but a Full Result's — its sign-up texts while it signs up
     * in place, its way-on texts while it sends the visitor on.
     *
     * @param  array<string, bool|int|float|string|null>  $settings  normalised
     * @return list<string>
     */
    private static function shownTexts(GameFlowStepType $type, array $settings): array
    {
        if ($type !== GameFlowStepType::FullResult) {
            return array_keys($type->texts());
        }

        return match (true) {
            $settings['onward'] !== GameOnward::Next->value => ['secondary_label', 'notice', 'button_label'],
            $settings['signup'] === GameSignupMode::Inline->value => ['title', 'body'],
            default => [],
        };
    }

    /**
     * What changed in one round kept from the version to the draft.
     *
     * @return list<string>
     */
    private static function round(SnapshotRound $published, SnapshotRound $draft): array
    {
        $changes = [
            self::text('Question', $published->question, $draft->question),
            self::text('Description', $published->description, $draft->description),
            $published->mode !== $draft->mode ? 'Round mode' : null,
            $published->scored !== $draft->scored ? 'Play without points' : null,
            // The crowd's pick follows the votes; only a correct answer is an administrator's.
            $published->mode === GameRoundMode::Objective && $draft->mode === GameRoundMode::Objective && $published->answer !== $draft->answer ? 'Correct answer' : null,
        ];

        $positions = array_unique([...array_map(fn (SnapshotOption $option): int => $option->position, $draft->options), ...array_map(fn (SnapshotOption $option): int => $option->position, $published->options)]);
        sort($positions);

        foreach ($positions as $position) {
            $name = VersusRoundCheck::optionName($position);
            $was = $published->option($position);
            $is = $draft->option($position);

            if ($was === null || $is === null) {
                $changes[] = $name.($was === null ? ' added' : ' removed');

                continue;
            }

            $changes[] = match (true) {
                $was->source !== $is->source => $name.' · Picture from',
                $is->source === GameOptionSource::Post && $was->postId !== $is->postId => $name.' · Post',
                $is->source === GameOptionSource::Upload && $was->assetId !== $is->assetId => $name.' · Picture',
                default => null,
            };

            $label = self::text('Answer label', $was->label, $is->label);
            $changes[] = $label === null ? null : $name.' · '.$label;
        }

        $changes[] = self::text('Reveal message', $published->reveal, $draft->reveal);
        $changes[] = $published->showCrowd !== $draft->showCrowd ? 'Show each post’s upvote share' : null;

        return array_values(array_filter($changes));
    }

    /**
     * What changed in one result profile kept from the version to the draft.
     *
     * @return list<string>
     */
    private static function profile(SnapshotProfile $published, SnapshotProfile $draft): array
    {
        return array_values(array_filter([
            self::text('Name', $published->name, $draft->name),
            [$published->min, $published->max] !== [$draft->min, $draft->max] ? 'Score range' : null,
            self::text('Description', $published->description, $draft->description),
            $published->icon !== $draft->icon ? 'Badge' : null,
            self::text('Share text', $published->shareText, $draft->shareText),
            $published->shareAssetId !== $draft->shareAssetId ? 'Share image' : null,
        ]));
    }

    /** A text's label when it changed — “· translations” when its English did not — or null. */
    private static function text(string $label, LocalizedText $published, LocalizedText $draft): ?string
    {
        $was = $published->toArray();
        $is = $draft->toArray();
        ksort($was);
        ksort($is);

        return match (true) {
            $was === $is => null,
            $published->english() !== $draft->english() => $label,
            default => $label.' · translations',
        };
    }

    /**
     * Each step's name as the Flow editor gives it: its kind, and its place
     * when the flow has more than one step of that kind.
     *
     * @param  list<SnapshotStep>  $steps
     * @return array<string, string>
     */
    private static function stepNames(array $steps): array
    {
        $kinds = array_count_values(array_map(fn (SnapshotStep $step): string => $step->type->value, $steps));
        $names = [];

        foreach ($steps as $index => $step) {
            $names[$step->key] = $step->type->label().($kinds[$step->type->value] > 1 ? ' (step '.($index + 1).')' : '');
        }

        return $names;
    }

    /** @return list<string> the rating groups a post is asked, in one order */
    private static function groupSet(bool|int|float|string|null $groups): array
    {
        $keys = array_values(array_filter(array_map(trim(...), explode(',', (string) $groups)), fn (string $key): bool => $key !== ''));
        sort($keys);

        return $keys;
    }

    private static function profileName(SnapshotProfile $profile): string
    {
        $name = trim($profile->name->english());

        return 'Profile “'.($name !== '' ? $name : $profile->key).'”';
    }

    /**
     * Whether the items both lists keep stand in another order in the draft.
     *
     * @param  list<string>  $published  every key of the version, in its order
     * @param  list<string>  $kept  the keys the draft kept, in its order
     */
    private static function reordered(array $published, array $kept): bool
    {
        return array_values(array_intersect($published, $kept)) !== $kept;
    }

    /**
     * The keys of items keyed by their key, in order, as the strings they are.
     *
     * @param  array<array-key, mixed>  $keyed
     * @return list<string>
     */
    private static function keys(array $keyed): array
    {
        return array_map(strval(...), array_keys($keyed));
    }

    /**
     * @template T of SnapshotStep|SnapshotRound|SnapshotProfile
     *
     * @param  list<T>  $items
     * @return array<string, T>
     */
    private static function byKey(array $items): array
    {
        $keyed = [];

        foreach ($items as $item) {
            $keyed[$item->key] = $item;
        }

        return $keyed;
    }
}
