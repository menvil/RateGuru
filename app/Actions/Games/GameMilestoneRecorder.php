<?php

namespace App\Actions\Games;

use App\Actions\Analytics\MarkAnalyticsPeriodHourAction;
use App\Enums\GameEventType;
use App\Enums\GameFlowGoal;
use App\Enums\GameMilestone;
use App\Events\Games\GameMilestoneReached;
use App\Models\GameAttempt;
use App\Support\Games\Play\GameVisitor;

/**
 * Records the moments of a play the traffic tracker counts, inside the
 * transaction of the action that reached them: it tells the tracker
 * (GameMilestoneReached), and when the moment is the goal of the attempt's
 * flow, marks the goal reached — the one place that does, at most once per
 * attempt (docs/architecture/games.md, "Goals").
 */
final class GameMilestoneRecorder
{
    public function __construct(
        private readonly GameEventRecorder $events,
        private readonly MarkAnalyticsPeriodHourAction $periodHours,
    ) {}

    public function reach(GameMilestone $milestone, GameAttempt $attempt, GameVisitor $visitor): void
    {
        event($this->reached($milestone, $attempt, $visitor));

        $goal = $attempt->version->flowGoal();

        if ($goal->milestone() === $milestone) {
            $this->markGoal($attempt, $visitor, $goal);
        }
    }

    /**
     * The goal of the attempt's flow reached, by whatever reached it — a
     * moment of the play, or an account confirmed for it.
     */
    public function markGoal(GameAttempt $attempt, GameVisitor $visitor, GameFlowGoal $goal): void
    {
        $marked = GameAttempt::query()->whereKey($attempt->id)->whereNull('goal_reached_at')->update(['goal_reached_at' => now()]);

        if ($marked !== 1) {
            return;
        }

        $attempt->setAttribute('goal_reached_at', now());
        $attempt->syncOriginalAttribute('goal_reached_at');
        // An account confirmed days after the play can reach its goal: the period's funnel counts it.
        $this->periodHours->ofAttempt($attempt);
        $this->events->record(GameEventType::GoalReached, (int) $attempt->game_id, $visitor, attempt: $attempt, detail: ['step_type' => $goal->value]);
        event(new GameMilestoneReached('goal', (int) $attempt->game_id, (int) $attempt->version_id, (int) $attempt->id, $attempt->traffic_visit_id, $visitor, (int) $attempt->version->round_count, $goal));
    }

    private function reached(GameMilestone $milestone, GameAttempt $attempt, GameVisitor $visitor): GameMilestoneReached
    {
        return new GameMilestoneReached($milestone, (int) $attempt->game_id, (int) $attempt->version_id, (int) $attempt->id, $attempt->traffic_visit_id, $visitor, (int) $attempt->version->round_count);
    }
}
