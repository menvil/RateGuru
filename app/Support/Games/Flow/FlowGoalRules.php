<?php

namespace App\Support\Games\Flow;

use App\Enums\GameFlowGoal;
use App\Enums\GameFlowStepType;
use App\Enums\GameOnward;
use App\Enums\GamePageDestination;
use App\Enums\GameSignupMode;
use App\Enums\GameStepAudience;

/**
 * Which step of a flow reaches a goal, and the goal a flow has when it names
 * none. An account is reached where the flow asks for one — a Registration
 * step, or sign-up on Full Result — or on the site, after a step that sends
 * the visitor there: voting or commenting asks for an account, and a sign-up
 * within the goal's window counts. When Full Result sends the visitor on to a
 * post or the feed, it is the way in, and the steps after it are never shown.
 */
final class FlowGoalRules
{
    /**
     * The index of the step that reaches the goal, or null when nothing in the
     * flow can. The game finished and played again are always within reach.
     *
     * @param  list<FlowStepDraft>  $steps
     */
    public static function stepFor(array $steps, GameFlowGoal $goal): ?int
    {
        $result = self::indexOf($steps, GameFlowStepType::FullResult);
        $onward = $result === null ? GameOnward::Next : $steps[$result]->onward();

        return match ($goal) {
            GameFlowGoal::Account, GameFlowGoal::NewAccount => self::accountStep($steps, $result, $onward),
            GameFlowGoal::Email => self::indexOf($steps, GameFlowStepType::EmailCapture),
            GameFlowGoal::Shared => self::indexOf($steps, GameFlowStepType::Share)
                ?? ($result !== null && (bool) $steps[$result]->setting('share_enabled') ? $result : null),
            GameFlowGoal::PostOpened, GameFlowGoal::PostVoted => self::postStep($steps, $result, $onward),
            GameFlowGoal::PostRated => self::ratedStep($steps, $result, $onward),
            GameFlowGoal::FeedVoted, GameFlowGoal::Feed => self::indexOf($steps, GameFlowStepType::Feed) ?? ($onward === GameOnward::Feed ? $result : null),
            GameFlowGoal::NextGame => self::indexOf($steps, GameFlowStepType::NextGame),
            GameFlowGoal::Page => self::indexOf($steps, GameFlowStepType::GoToPage),
            GameFlowGoal::Replayed => $result,
            GameFlowGoal::Completed => self::indexOf($steps, GameFlowStepType::Game),
        };
    }

    /** @param  list<FlowStepDraft>  $steps */
    public static function reaches(array $steps, GameFlowGoal $goal): bool
    {
        return $goal->needs() === null || self::stepFor($steps, $goal) !== null;
    }

    /**
     * Whether the goal is reached on the site, after the step that sends the
     * visitor there, rather than on a screen of the flow.
     *
     * @param  list<FlowStepDraft>  $steps
     */
    public static function viaSite(array $steps, GameFlowGoal $goal): bool
    {
        if (! in_array($goal, [GameFlowGoal::Account, GameFlowGoal::NewAccount], true)) {
            return false;
        }

        $index = self::stepFor($steps, $goal);

        if ($index === null) {
            return false;
        }

        $step = $steps[$index];

        return in_array($step->type, [GameFlowStepType::GoToPost, GameFlowStepType::GoToPage, GameFlowStepType::Feed], true)
            || ($step->type === GameFlowStepType::FullResult && $step->onward() !== GameOnward::Next);
    }

    /**
     * The goal a flow has when it names none: what its steps are evidently
     * there for, the first that fits of a way into the site, an account asked
     * for, the next game, an email, a share, the feed — else an account when
     * one can be reached, else the game finished.
     *
     * @param  list<FlowStepDraft>  $steps
     */
    public static function defaultFor(array $steps): GameFlowGoal
    {
        if (self::indexOf($steps, GameFlowStepType::GoToPage) !== null) {
            return GameFlowGoal::Page;
        }

        $post = self::postSender($steps);

        if ($post !== null) {
            return match (true) {
                (bool) $post->setting('ask_vote') => GameFlowGoal::PostVoted,
                $post->askGroups() !== [] => GameFlowGoal::PostRated,
                default => GameFlowGoal::PostOpened,
            };
        }

        return match (true) {
            self::indexOf($steps, GameFlowStepType::Registration) !== null => GameFlowGoal::Account,
            self::indexOf($steps, GameFlowStepType::NextGame) !== null => GameFlowGoal::NextGame,
            self::indexOf($steps, GameFlowStepType::EmailCapture) !== null => GameFlowGoal::Email,
            self::indexOf($steps, GameFlowStepType::Share) !== null => GameFlowGoal::Shared,
            self::stepFor($steps, GameFlowGoal::Feed) !== null => GameFlowGoal::Feed,
            self::stepFor($steps, GameFlowGoal::Account) !== null => GameFlowGoal::Account,
            default => GameFlowGoal::Completed,
        };
    }

    /** @param  list<FlowStepDraft>  $steps */
    private static function accountStep(array $steps, ?int $result, GameOnward $onward): ?int
    {
        if ($result !== null && $onward !== GameOnward::Next) {
            return $result;
        }

        $registration = self::indexOf($steps, GameFlowStepType::Registration);

        if ($registration !== null) {
            return $registration;
        }

        if ($result !== null && $steps[$result]->signup() === GameSignupMode::Inline) {
            return $result;
        }

        foreach ($steps as $index => $step) {
            $intoTheSite = match ($step->type) {
                GameFlowStepType::GoToPost, GameFlowStepType::Feed => true,
                GameFlowStepType::GoToPage => $step->destination() !== GamePageDestination::Url,
                default => false,
            };

            if ($intoTheSite && $step->audience !== GameStepAudience::Authenticated) {
                return $index;
            }
        }

        return null;
    }

    /** @param  list<FlowStepDraft>  $steps */
    private static function postStep(array $steps, ?int $result, GameOnward $onward): ?int
    {
        return self::indexOf($steps, GameFlowStepType::GoToPost) ?? ($onward === GameOnward::Post ? $result : null);
    }

    /** @param  list<FlowStepDraft>  $steps */
    private static function ratedStep(array $steps, ?int $result, GameOnward $onward): ?int
    {
        $index = self::postStep($steps, $result, $onward);

        return $index !== null && $steps[$index]->askGroups() !== [] ? $index : null;
    }

    /**
     * The step that sends the visitor to a post, if any.
     *
     * @param  list<FlowStepDraft>  $steps
     */
    private static function postSender(array $steps): ?FlowStepDraft
    {
        foreach ($steps as $step) {
            if ($step->sendsToPost()) {
                return $step;
            }
        }

        return null;
    }

    /** @param  list<FlowStepDraft>  $steps */
    private static function indexOf(array $steps, GameFlowStepType $type): ?int
    {
        foreach ($steps as $index => $step) {
            if ($step->type === $type) {
                return $index;
            }
        }

        return null;
    }
}
