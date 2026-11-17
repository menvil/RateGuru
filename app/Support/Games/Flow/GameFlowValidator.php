<?php

namespace App\Support\Games\Flow;

use App\Enums\GameFlowGoal;
use App\Enums\GameFlowStage;
use App\Enums\GameFlowStepType;
use App\Enums\GameOnward;
use App\Enums\GamePageDestination;
use App\Enums\GamePostTarget;
use App\Enums\GameSignupMode;
use App\Enums\GameStepAudience;

/**
 * Whether a flow can be played: the game exactly once, a result after it,
 * every step where its stage allows, no step twice, one way out at most, and
 * the texts each step needs. The same rules tell an editor where a step may
 * be added.
 */
final class GameFlowValidator
{
    /**
     * With a goal, also whether the flow can reach it; with facts read from
     * the site, also whether the posts, groups, categories, tags and pages its
     * steps name are there.
     *
     * @param  list<FlowStepDraft>  $steps
     * @return list<FlowIssue>
     */
    public static function issues(array $steps, ?GameFlowGoal $goal = null, ?FlowFacts $facts = null): array
    {
        $issues = [];
        $first = [];
        $gameAt = null;
        $fullResultAt = null;
        $exits = 0;

        foreach ($steps as $index => $step) {
            $type = $step->type;

            if (isset($first[$type->value])) {
                $issues[] = new FlowIssue($index, $type === GameFlowStepType::Game
                    ? 'Second Game step. A flow plays the game once; remove this one.'
                    : sprintf('Duplicate %s step. Step %d already does this.', $type->label(), $first[$type->value] + 1));

                continue;
            }

            $first[$type->value] = $index;

            if ($type === GameFlowStepType::Game) {
                $gameAt = $index;
            }

            if ($type === GameFlowStepType::FullResult) {
                $fullResultAt = $index;
            }
        }

        if ($gameAt === null) {
            $issues[] = new FlowIssue(-1, 'Missing Game step. Every flow plays the game exactly once.');
        }

        if ($fullResultAt === null) {
            $issues[] = new FlowIssue(-1, 'Missing Full Result. Players never see their result; add a Full Result after the game.');
        }

        $lastIndex = count($steps) - 1;

        foreach ($steps as $index => $step) {
            if (($first[$step->type->value] ?? null) !== $index) {
                continue;
            }

            $type = $step->type;
            $stage = $type->stage();

            if ($gameAt !== null && $stage === GameFlowStage::AfterGame && $index < $gameAt) {
                $issues[] = new FlowIssue($index, "{$type->label()} comes before the Game step. Results can only follow gameplay.");
            } elseif ($gameAt !== null && $stage === GameFlowStage::BeforeGame && $index > $gameAt) {
                $issues[] = new FlowIssue($index, "{$type->label()} comes after the Game step. Move it before the game.");
            }

            if ($stage === GameFlowStage::Exit) {
                $exits++;

                if ($exits > 1) {
                    $issues[] = new FlowIssue($index, 'Use one exit step: Next Game, Go to Feed, Go to Post or Go to Page.');
                } elseif ($index !== $lastIndex) {
                    $issues[] = new FlowIssue($index, "{$type->label()} ends the flow, so it must be the last step.");
                }
            }

            if ($fullResultAt !== null) {
                if ($type === GameFlowStepType::ResultPreview && $index > $fullResultAt) {
                    $issues[] = new FlowIssue($index, 'Result Preview comes after the Full Result. A preview has to come first.');
                }

                if ($type === GameFlowStepType::Share && $index < $fullResultAt) {
                    $issues[] = new FlowIssue($index, 'Share comes before the result. Players can only share a result they have seen.');
                }

                if ($type === GameFlowStepType::Registration && $index < $fullResultAt) {
                    $issues[] = new FlowIssue($index, 'Registration CTA comes before the Full Result. Invite players to sign up after they see their result.');
                }
            } elseif ($type === GameFlowStepType::ResultPreview) {
                $issues[] = new FlowIssue($index, 'Result Preview needs a Full Result after it.');
            }

            $fixed = $type->fixedAudience();

            if ($fixed !== null && $step->audience !== $fixed) {
                $issues[] = new FlowIssue($index, "{$type->label()} is shown to anonymous visitors only. A signed-in player already has an account and an email.");
            }

            if ($type === GameFlowStepType::NextGame && $step->setting('next_game_id') === null) {
                $issues[] = new FlowIssue($index, 'Choose the next game.');
            }

            $missing = [...$step->missingTexts(), ...self::missingSettings($step)];

            if ($missing !== []) {
                $issues[] = new FlowIssue($index, 'Missing settings: '.implode(', ', $missing).'.');
            }

            if ($facts !== null) {
                array_push($issues, ...self::factIssues($index, $step, $facts));
            }
        }

        $onward = $fullResultAt === null ? GameOnward::Next : $steps[$fullResultAt]->onward();

        if ($onward !== GameOnward::Next) {
            $where = $onward === GameOnward::Post ? 'a post' : 'the feed';

            foreach ($steps as $index => $step) {
                if ($index > $fullResultAt && ($first[$step->type->value] ?? null) === $index) {
                    $issues[] = new FlowIssue($index, $step->type === GameFlowStepType::Registration
                        ? "Full Result sends visitors on to {$where}, so this step is never shown. They can still sign up there: voting asks for an account, and the goal counts a sign-up within 7 days. Remove this step."
                        : "Full Result already sends visitors on to {$where}, so this step is never shown. Remove it, or set Full Result to go on to the next step.");
                }
            }
        }

        if ($fullResultAt !== null && $onward === GameOnward::Next) {
            $signup = $steps[$fullResultAt]->signup();
            $registrationAt = $first[GameFlowStepType::Registration->value] ?? null;

            if ($signup === GameSignupMode::Step && $registrationAt === null) {
                $issues[] = new FlowIssue($fullResultAt, 'Full Result sends sign-up to a Registration step, but the flow has none. Add Registration or show sign-up on the result.');
            }

            if ($signup !== GameSignupMode::Step && $registrationAt !== null) {
                $issues[] = new FlowIssue($registrationAt, $signup === GameSignupMode::None
                    ? 'Full Result is set to no sign-up. Remove this step, or set Full Result to a separate Registration step.'
                    : 'Sign-up is already on Full Result. Remove this step, or set Full Result to a separate Registration step.');
            }
        }

        if ($goal !== null && ! FlowGoalRules::reaches($steps, $goal)) {
            $issues[] = new FlowIssue(-1, "The goal “{$goal->label()}” needs {$goal->needs()}. Add it, or choose another goal.");
        }

        return $issues;
    }

    /**
     * The settings a step needs and lacks, by their labels: the post a step
     * sends visitors to when it is chosen, the category or tag a Go to Page
     * leads to — one that is no longer active counts as none — and an address
     * that is not one.
     *
     * @return list<string>
     */
    private static function missingSettings(FlowStepDraft $step): array
    {
        $missing = [];

        if ($step->sendsToPost() && $step->postTarget() === GamePostTarget::Chosen && $step->setting('post_id') === null) {
            $missing[] = 'Post';
        }

        if ($step->type !== GameFlowStepType::GoToPage) {
            return $missing;
        }

        $category = (string) $step->setting('category');
        $tag = (string) $step->setting('tag');

        return match ($step->destination()) {
            GamePageDestination::Category => $category === '' ? [...$missing, 'Category'] : $missing,
            GamePageDestination::Tag => $tag === '' ? [...$missing, 'Tag'] : $missing,
            GamePageDestination::Page => preg_match('#^/(?!/)#', (string) $step->setting('path')) !== 1 ? [...$missing, 'Page address'] : $missing,
            GamePageDestination::Url => ! self::isSiteAddress((string) $step->setting('url')) ? [...$missing, 'Site address'] : $missing,
        };
    }

    /**
     * Another site's address a Go to Page step may send visitors to: https, a
     * host with a dot, no user name or password, and no placeholder but the
     * click id, the game and the result.
     */
    public static function isSiteAddress(string $url): bool
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || ! str_contains((string) ($parts['host'] ?? ''), '.') || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        preg_match_all('/\{([^{}]*)\}/', $url, $placeholders);

        return array_diff($placeholders[1], ['click_id', 'game', 'result']) === [];
    }

    /** @return list<FlowIssue> what the site says of the posts and pages a step names */
    private static function factIssues(int $index, FlowStepDraft $step, FlowFacts $facts): array
    {
        $issues = [];
        $postId = $step->setting('post_id');

        if ($step->sendsToPost() && $step->postTarget() === GamePostTarget::Chosen && is_int($postId)) {
            $post = $facts->posts[$postId] ?? null;

            if ($post === null) {
                $issues[] = new FlowIssue($index, 'The linked post no longer exists. Link another post.');
            } elseif (! $post['published']) {
                $issues[] = new FlowIssue($index, "The linked post “{$post['title']}” is hidden, so visitors can’t open it. Link another post, or publish it again.");
            }
        }

        if ($step->sendsToPost()) {
            foreach ($step->askGroups() as $key) {
                if (! in_array($key, $facts->ratingGroups, true)) {
                    $issues[] = new FlowIssue($index, "The post asks for the rating group “{$key}”, which is no longer active. Untick it.");
                }
            }
        }

        $category = (string) $step->setting('category');
        $tag = (string) $step->setting('tag');

        if ($step->type === GameFlowStepType::GoToPage && $step->destination() === GamePageDestination::Category && $category !== '' && ! in_array($category, $facts->categories, true)) {
            $issues[] = new FlowIssue($index, "The category “{$category}” isn’t active on the site. Choose another category.");
        }

        if ($step->type === GameFlowStepType::GoToPage && $step->destination() === GamePageDestination::Tag && $tag !== '' && ! in_array($tag, $facts->tags, true)) {
            $issues[] = new FlowIssue($index, "There is no tag “{$tag}” on the site. Choose another tag.");
        }

        if ($step->type === GameFlowStepType::GoToPage && $step->destination() === GamePageDestination::Page) {
            $path = (string) $step->setting('path');

            if (preg_match('#^/(?!/)#', $path) === 1 && ! ($facts->paths[$path] ?? false)) {
                $issues[] = new FlowIssue($index, "“{$path}” isn’t a page of this site. Use the address of the feed, a post, a profile, a game or a static page.");
            }
        }

        return $issues;
    }

    /**
     * The types that can be added at a position (0 = before the first step)
     * without adding a problem the flow does not already have: every problem
     * of the flow with the step added is one it had before. A step that
     * settles one problem by causing another is not offered there. The game
     * is offered only to a flow without one.
     *
     * @param  list<FlowStepDraft>  $steps
     * @return list<GameFlowStepType>
     */
    public static function insertableAt(array $steps, int $position): array
    {
        $present = array_map(fn (FlowStepDraft $step): string => $step->type->value, $steps);
        $baseline = array_count_values(self::structuralProblems($steps));
        $fits = [];

        foreach (GameFlowStepType::cases() as $type) {
            if (in_array($type->value, $present, true)) {
                continue;
            }

            $candidate = $steps;
            array_splice($candidate, $position, 0, [self::fresh($type, flow: $steps)]);
            $added = array_filter(
                array_count_values(self::structuralProblems($candidate)),
                fn (int $count, string $problem): bool => $count > ($baseline[$problem] ?? 0),
                ARRAY_FILTER_USE_BOTH,
            );

            if ($added === []) {
                $fits[] = $type;
            }
        }

        return $fits;
    }

    /**
     * Where a step of a type fits best: the first position, from the end of
     * its stage backwards, that adds no problem; null when none does.
     *
     * @param  list<FlowStepDraft>  $steps
     */
    public static function defaultPosition(array $steps, GameFlowStepType $type): ?int
    {
        for ($position = count($steps); $position >= 0; $position--) {
            if (in_array($type, self::insertableAt($steps, $position), true)) {
                return $position;
            }
        }

        return null;
    }

    /**
     * A new step of a type, as an editor adds it to a flow: a Full Result
     * added to a flow with a Registration step sends sign-up there, rather
     * than inviting it a second time on itself.
     *
     * @param  list<FlowStepDraft>  $flow
     */
    public static function fresh(GameFlowStepType $type, string $key = 'new', array $flow = []): FlowStepDraft
    {
        $settings = $type->defaultSettings();

        if ($type === GameFlowStepType::FullResult && in_array(GameFlowStepType::Registration, array_map(fn (FlowStepDraft $step): GameFlowStepType => $step->type, $flow), true)) {
            $settings['signup'] = GameSignupMode::Step->value;
        }

        return new FlowStepDraft(
            key: $key,
            type: $type,
            audience: $type->fixedAudience() ?? GameStepAudience::All,
            settings: $settings,
            texts: $type->defaultTexts(),
        );
    }

    /**
     * Problems that come from where steps stand, not from what they say: a
     * new step's texts are complete, so its own missing texts never count.
     * Each is its message without the step number a duplicate names, which
     * a step added before that number moves without changing the problem.
     *
     * @param  list<FlowStepDraft>  $steps
     * @return list<string>
     */
    private static function structuralProblems(array $steps): array
    {
        $problems = [];

        foreach (self::issues($steps) as $issue) {
            if (str_starts_with($issue->message, 'Missing settings:') || $issue->message === 'Choose the next game.') {
                continue;
            }

            $problems[] = preg_replace('/ Step \d+ already does this\.\z/', '', $issue->message) ?? $issue->message;
        }

        return $problems;
    }
}
