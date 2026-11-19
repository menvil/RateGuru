<?php

namespace App\Support\Traffic\Conversions;

use App\Actions\Traffic\MarkTrafficVisitAction;
use App\Enums\GameMilestone;
use App\Enums\TrafficEvent;
use App\Events\Games\GameMilestoneReached;
use App\Support\Traffic\Postbacks\MilestoneContext;
use Carbon\CarbonImmutable;

/**
 * A moment of a play, told to the visit the play came on: the game's
 * milestones and its goal become the visit's marks, conversions and
 * postbacks (MarkTrafficVisitAction). It runs synchronously inside the
 * game action's transaction, so a game write undone takes the tracker's
 * with it. A play that came on no recorded visit tells nothing.
 */
final class RecordGameMilestoneOnVisit
{
    public function __construct(private readonly MarkTrafficVisitAction $marks) {}

    public function handle(GameMilestoneReached $event): void
    {
        if ($event->visitId === null) {
            return;
        }

        $traffic = $event->isGoal() ? TrafficEvent::Goal : self::eventOf($event->milestone);

        if ($traffic === null) {
            return;
        }

        $now = CarbonImmutable::now();
        $answered = match ($traffic) {
            TrafficEvent::Halfway => (int) ceil($event->roundCount / 2),
            TrafficEvent::Completed => $event->roundCount,
            default => 0,
        };

        if ($answered > 0) {
            $this->marks->answered($event->visitId, $answered);
        }

        $this->marks->handle($event->visitId, $traffic, $now, context: new MilestoneContext(
            attemptId: $event->attemptId,
            gameId: $event->gameId,
            versionId: $event->versionId,
            rounds: $event->roundCount,
            goal: $event->goal,
        ));
    }

    private static function eventOf(GameMilestone|string $milestone): ?TrafficEvent
    {
        return $milestone instanceof GameMilestone ? TrafficEvent::tryFrom($milestone->value) : null;
    }
}
