<?php

namespace App\Actions\Games;

use App\Enums\GameAttemptStatus;
use App\Enums\GameEventType;
use App\Enums\GameFlowStepType;
use App\Exceptions\Games\CannotPlayGameException;
use App\Models\GameAnswer;
use App\Models\GameAttempt;
use App\Queries\Games\GameFlowVisitorQuery;
use App\Support\AbuseGuards\ActionRateLimiter;
use App\Support\AbuseGuards\RateLimitKey;
use App\Support\Games\Flow\GameFlowRunner;
use App\Support\Games\Play\GameVisitor;
use App\Support\Games\Scoring\GameScorer;
use Illuminate\Support\Facades\DB;

/**
 * Records a pick. The attempt's row is locked; only the next round in order
 * may be answered, once — the same pick sent again, from a second tab or a
 * retry, gets the answer already recorded. Its outcome is read from the
 * version's snapshot, never from the browser and never from today's votes.
 * The last pick completes the attempt: matches, score and profile worked out
 * on the server, and the attempt moved on to the step after the game.
 */
final class RecordGameAnswerAction
{
    public function __construct(
        private readonly ActionRateLimiter $limiter,
        private readonly GameFlowVisitorQuery $flowVisitors,
        private readonly GameEventRecorder $events,
    ) {}

    /** @throws CannotPlayGameException */
    public function handle(GameAttempt $attempt, GameVisitor $visitor, int $roundIndex, int $position): GameAnswer
    {
        [$most, $per] = config('games.rate_limits.answer');
        $this->limiter->hitOrFail(RateLimitKey::gameVisitorAction('game-answer', $visitor->identityKey()), (int) $most, (int) $per);

        return DB::transaction(function () use ($attempt, $visitor, $roundIndex, $position): GameAnswer {
            $locked = GameAttempt::query()->whereKey($attempt->id)->lockForUpdate()->first();

            if ($locked === null) {
                throw CannotPlayGameException::becauseAttemptIsOver();
            }

            $existing = GameAnswer::query()->where('attempt_id', $locked->id)->where('round_index', $roundIndex)->first();

            if ($existing !== null) {
                $attempt->setRawAttributes($locked->getAttributes(), true);

                return $existing;
            }

            $game = $locked->game;
            $snapshot = $locked->version->read();
            $round = $snapshot->round($roundIndex);

            if ($locked->status !== GameAttemptStatus::Started || ! $game->status->letsStartedAttemptsFinish()
                || $roundIndex !== $locked->rounds_answered || $round === null || $round->option($position) === null) {
                throw CannotPlayGameException::becauseAttemptIsOver();
            }

            $judged = GameScorer::judge($round, $position, $snapshot->pointsPerRound);

            $answer = new GameAnswer;
            $answer->forceFill([
                'attempt_id' => $locked->id,
                'round_index' => $roundIndex,
                'round_key' => $round->key,
                'option_position' => $position,
                'outcome' => $judged->outcome,
                'points' => $judged->points,
                'answered_at' => now(),
            ])->save();

            $locked->forceFill(['rounds_answered' => $roundIndex + 1, 'last_activity_at' => now()]);
            $this->events->record(GameEventType::RoundAnswered, (int) $locked->game_id, $visitor, attempt: $locked, detail: ['round_index' => $roundIndex]);

            if ($roundIndex + 1 === $snapshot->roundCount()) {
                $outcomes = GameAnswer::query()->where('attempt_id', $locked->id)->orderBy('round_index')->pluck('outcome')->all();
                $result = GameScorer::result($snapshot, $outcomes);
                $steps = $snapshot->flowSteps();
                $gameStep = GameFlowRunner::ofType($steps, GameFlowStepType::Game);
                $next = $gameStep !== null ? GameFlowRunner::after($steps, $this->flowVisitors->for($visitor, $snapshot, $visitor->audience), $gameStep->key) : null;

                $locked->forceFill([
                    'status' => GameAttemptStatus::Completed,
                    'matches' => $result->matches,
                    'score' => $result->score,
                    'max_score' => $result->maxScore,
                    'profile_key' => $result->profile?->key,
                    'completed_at' => now(),
                    'current_step_key' => $next?->key,
                ]);
                $this->events->record(GameEventType::Completed, (int) $locked->game_id, $visitor, attempt: $locked);

                // Entering the step after the game is a step like any other the
                // attempt moves on to, so it is counted the way a move counts it.
                if ($next !== null) {
                    $this->events->record($next->type === GameFlowStepType::FullResult ? GameEventType::ResultViewed : GameEventType::StepViewed, (int) $locked->game_id, $visitor, attempt: $locked, detail: ['step_type' => $next->type->value]);
                }
            }

            $locked->save();
            $attempt->setRawAttributes($locked->getAttributes(), true);

            return $answer;
        });
    }
}
