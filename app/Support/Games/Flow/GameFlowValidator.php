<?php

namespace App\Support\Games\Flow;

use App\Enums\GameFlowStage;
use App\Enums\GameFlowStepType;
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
     * @param  list<FlowStepDraft>  $steps
     * @return list<FlowIssue>
     */
    public static function issues(array $steps): array
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
                    $issues[] = new FlowIssue($index, 'Use one exit step: Next Game or Go to Feed.');
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

            $missing = $step->missingTexts();

            if ($missing !== []) {
                $issues[] = new FlowIssue($index, 'Missing settings: '.implode(', ', $missing).'.');
            }
        }

        if ($fullResultAt !== null) {
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
