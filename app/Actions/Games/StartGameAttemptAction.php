<?php

namespace App\Actions\Games;

use App\Enums\GameAttemptStatus;
use App\Enums\GameEventType;
use App\Enums\GameFlowStepType;
use App\Enums\GameMilestone;
use App\Enums\GameOnwardKind;
use App\Exceptions\Games\CannotPlayGameException;
use App\Models\Game;
use App\Models\GameAttempt;
use App\Models\GameVersion;
use App\Models\TrafficVisit;
use App\Queries\Games\GameTrafficQuery;
use App\Support\AbuseGuards\ActionRateLimiter;
use App\Support\AbuseGuards\RateLimitKey;
use App\Support\Games\Flow\GameFlowRunner;
use App\Support\Games\Play\GameVisitor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Starts an attempt: the visitor pressed Start, or made their first pick in a
 * flow that opens on the game. The attempt is pinned to the version the
 * visitor is assigned now, for good; a replay starts another on the same
 * terms. Only a live game starts attempts, and only on a version that can be
 * played.
 */
final class StartGameAttemptAction
{
    public function __construct(
        private readonly AssignGameVersionAction $assign,
        private readonly GameTrafficQuery $traffic,
        private readonly ActionRateLimiter $limiter,
        private readonly GameEventRecorder $events,
        private readonly GameMilestoneRecorder $milestones,
    ) {}

    /**
     * The attempt is started under the traffic tracker's visit the page was
     * credited to, when it is this browser's: the visit then shows the game
     * version the attempt plays, and its later steps go to it.
     *
     * @throws CannotPlayGameException
     */
    public function handle(Game $game, GameVisitor $visitor, string $locale, bool $replay = false, ?int $visitId = null, ?GameAttempt $from = null): GameAttempt
    {
        if (! $game->status->acceptsNewAttempts()) {
            throw CannotPlayGameException::becauseGameIsNotLive();
        }

        [$most, $per] = config('games.rate_limits.start');
        $this->limiter->hitOrFail(RateLimitKey::gameVisitorAction('game-start', $visitor->identityKey()), (int) $most, (int) $per);

        $assignment = $this->assign->handle($game, $visitor);
        $version = $assignment !== null ? GameVersion::query()->find($assignment->version_id) : null;

        if ($version === null || $this->traffic->unplayableVersionIds([(int) $version->id]) !== []) {
            throw CannotPlayGameException::becauseNothingCanBePlayed();
        }

        $game = $game->fresh() ?? $game;

        if (! $game->status->acceptsNewAttempts()) {
            throw CannotPlayGameException::becauseGameIsNotLive();
        }

        $visit = $visitId === null || $visitor->browserHash === null ? null
            : TrafficVisit::query()->whereKey($visitId)->where('visitor_hash', $visitor->browserHash)->first(['id', 'game_id', 'version_id']);

        return DB::transaction(function () use ($game, $visitor, $locale, $replay, $assignment, $version, $visit, $from): GameAttempt {
            $gameStep = GameFlowRunner::ofType($version->read()->flowSteps(), GameFlowStepType::Game);

            $attempt = new GameAttempt;
            $attempt->forceFill([
                'public_id' => (string) Str::ulid(),
                'game_id' => $game->id,
                'version_id' => $version->id,
                'assignment_id' => $assignment->id,
                'traffic_visit_id' => $visit?->id,
                'audience' => $visitor->audience,
                'user_id' => $visitor->userId,
                'visitor_hash' => $visitor->visitorHash,
                'status' => GameAttemptStatus::Started,
                'current_step_key' => $gameStep?->key,
                'rounds_answered' => 0,
                'max_score' => $version->max_score,
                'locale' => $locale,
                'started_at' => now(),
                'last_activity_at' => now(),
            ])->save();

            if ($visit !== null && $visit->version_id === null && ($visit->game_id === null || (int) $visit->game_id === (int) $game->id)) {
                TrafficVisit::query()->whereKey($visit->id)->whereNull('version_id')->update(['game_id' => $game->id, 'version_id' => $version->id]);
            }

            $this->events->record(GameEventType::Started, (int) $game->id, $visitor, attempt: $attempt);

            $this->milestones->reach(GameMilestone::Started, $attempt, $visitor);

            if ($replay) {
                $this->events->record(GameEventType::Replay, (int) $game->id, $visitor, attempt: $attempt);

                // Played again: the play the visitor came back from reached it.
                if ($from !== null && (int) $from->game_id === (int) $game->id) {
                    $this->milestones->reach(GameMilestone::Replayed, $from, $visitor);
                }
            }

            $this->creditNextGame($game, $visitor);

            return $attempt;
        });
    }

    /**
     * A start of the game another game's Next Game step sent the visitor to,
     * within 30 minutes, is that play's "next game started".
     */
    private function creditNextGame(Game $game, GameVisitor $visitor): void
    {
        $sender = GameAttempt::query()
            ->where('game_id', '!=', $game->id)
            ->where('onward_kind', GameOnwardKind::NextGame)
            ->where('onward_at', '>=', now()->subMinutes(30))
            ->where(fn ($query) => $visitor->userId !== null
                ? $query->where('user_id', $visitor->userId)
                : $query->where('visitor_hash', $visitor->visitorHash)->whereNull('user_id'))
            ->orderByDesc('onward_at')
            ->first();

        if ($sender === null) {
            return;
        }

        $step = GameFlowRunner::ofType($sender->version->read()->flowSteps(), GameFlowStepType::NextGame);

        if ($step !== null && (int) $step->setting('next_game_id') === (int) $game->id) {
            $this->milestones->reach(GameMilestone::NextGame, $sender, $visitor);
        }
    }
}
