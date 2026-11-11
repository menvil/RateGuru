<?php

namespace App\Actions\Games;

use App\Enums\GameAttemptStatus;
use App\Enums\GameEventType;
use App\Enums\GameFlowStepType;
use App\Exceptions\Games\CannotPlayGameException;
use App\Models\Game;
use App\Models\GameAttempt;
use App\Models\GameVersion;
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
    ) {}

    /** @throws CannotPlayGameException */
    public function handle(Game $game, GameVisitor $visitor, string $locale, bool $replay = false): GameAttempt
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

        return DB::transaction(function () use ($game, $visitor, $locale, $replay, $assignment, $version): GameAttempt {
            $gameStep = GameFlowRunner::ofType($version->read()->flowSteps(), GameFlowStepType::Game);

            $attempt = new GameAttempt;
            $attempt->forceFill([
                'public_id' => (string) Str::ulid(),
                'game_id' => $game->id,
                'version_id' => $version->id,
                'assignment_id' => $assignment->id,
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

            $this->events->record(GameEventType::Started, (int) $game->id, $visitor, attempt: $attempt);

            if ($replay) {
                $this->events->record(GameEventType::Replay, (int) $game->id, $visitor, attempt: $attempt);
            }

            return $attempt;
        });
    }
}
