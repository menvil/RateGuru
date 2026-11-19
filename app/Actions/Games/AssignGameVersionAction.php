<?php

namespace App\Actions\Games;

use App\Enums\GameEventType;
use App\Models\Game;
use App\Models\GameAssignment;
use App\Queries\Games\GameTrafficQuery;
use App\Support\AbuseGuards\RateLimitKey;
use App\Support\Games\Play\GameVisitor;
use App\Support\Games\Traffic\TrafficScope;
use App\Support\Games\Traffic\VersionAssigner;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Gives a visitor of a live game a version, and records that they saw the
 * game. A visitor keeps the version they were given while it still has a share
 * in any open period of their audience and can be played — whichever split
 * they came through, and wherever they come from now; otherwise their
 * assignment ends and a new one is made by the shares in effect — the same
 * pick for the same game, period and visitor, so the page drawn before
 * anything was stored and the assignment agree.
 *
 * The shares in effect are those of the open period that covers the arrival
 * most closely: an anonymous visitor a paid click brought gets the split for
 * its campaign, else for its source, else the general one; a signed-in player
 * and every other arrival get the general one (GameTrafficQuery::assignable).
 *
 * Asked by the page once the browser is running it, so a client that runs no
 * script — a crawler — is neither assigned nor counted.
 */
final class AssignGameVersionAction
{
    public function __construct(
        private readonly GameTrafficQuery $traffic,
        private readonly GameEventRecorder $events,
    ) {}

    /**
     * The version a visitor would be given now, without storing anything; null when none can be.
     *
     * @param  TrafficScope|null  $arrival  the paid click that brought the visitor, by its source and campaign; everyone's when none did
     */
    public function preview(Game $game, GameVisitor $visitor, ?TrafficScope $arrival = null): ?int
    {
        $open = $this->openAssignment($game, $visitor);
        ['period' => $period, 'shares' => $shares, 'kept' => $kept] = $this->traffic->assignable((int) $game->id, $visitor->audience, self::scopeOf($visitor, $arrival));

        if ($open !== null && in_array((int) $open->version_id, $kept, true)) {
            return $open->version_id;
        }

        return $period === null ? null : VersionAssigner::choose($shares, $this->seed($game, (int) $period->id, $visitor));
    }

    /**
     * @param  bool  $viewed  whether the visitor is opening the game's page — a `view` — rather than starting from it
     * @param  TrafficScope|null  $arrival  the paid click that brought the visitor, by its source and campaign; everyone's when none did
     */
    public function handle(Game $game, GameVisitor $visitor, bool $viewed = false, ?TrafficScope $arrival = null): ?GameAssignment
    {
        if (! $game->status->acceptsNewAttempts()) {
            return null;
        }

        ['period' => $period, 'shares' => $shares, 'kept' => $kept] = $this->traffic->assignable((int) $game->id, $visitor->audience, self::scopeOf($visitor, $arrival));
        $open = $this->openAssignment($game, $visitor);

        $assignment = DB::transaction(function () use ($game, $visitor, $period, $shares, $kept, $open): ?GameAssignment {
            if ($open !== null && in_array((int) $open->version_id, $kept, true)) {
                $open->forceFill(['last_seen_at' => now()])->save();

                return $open;
            }

            $open?->forceFill(['active_key' => null, 'ended_at' => now()])->save();

            if ($period === null) {
                return null;
            }

            $versionId = VersionAssigner::choose($shares, $this->seed($game, (int) $period->id, $visitor));

            if ($versionId === null) {
                return null;
            }

            try {
                // A savepoint: on PostgreSQL a failed insert would otherwise
                // abort the whole transaction.
                return DB::transaction(function () use ($game, $visitor, $versionId, $period): GameAssignment {
                    $assignment = new GameAssignment;
                    $assignment->forceFill([
                        'game_id' => $game->id,
                        'audience' => $visitor->audience,
                        'user_id' => $visitor->userId,
                        'visitor_hash' => $visitor->userId === null ? $visitor->visitorHash : null,
                        'active_key' => $visitor->identityKey(),
                        'version_id' => $versionId,
                        'period_id' => $period->id,
                        'assigned_at' => now(),
                        'last_seen_at' => now(),
                    ])->save();

                    $this->events->record(GameEventType::Assigned, (int) $game->id, $visitor, assignment: $assignment);

                    return $assignment;
                });
            } catch (UniqueConstraintViolationException) {
                // Another tab of the same visitor was faster: theirs stands.
                return $this->openAssignment($game, $visitor);
            }
        });

        // A page load is a view; a burst of them from one visitor counts once a moment.
        $key = RateLimitKey::gameVisitorAction('game-view-'.$game->id, $visitor->identityKey());
        [$most, $per] = config('games.rate_limits.view');

        if ($viewed && RateLimiter::hit($key, (int) $per) <= (int) $most) {
            $this->events->record(GameEventType::View, (int) $game->id, $visitor, assignment: $assignment);
        }

        return $assignment;
    }

    /** Splits for a source are for anonymous visitors: anyone else is everyone. */
    private static function scopeOf(GameVisitor $visitor, ?TrafficScope $arrival): TrafficScope
    {
        return $visitor->isAnonymous() && $arrival !== null ? $arrival : TrafficScope::everyone();
    }

    private function openAssignment(Game $game, GameVisitor $visitor): ?GameAssignment
    {
        return GameAssignment::query()
            ->where('game_id', $game->id)
            ->where('audience', $visitor->audience)
            ->where('active_key', $visitor->identityKey())
            ->first();
    }

    private function seed(Game $game, int $periodId, GameVisitor $visitor): string
    {
        return "game:{$game->id}|period:{$periodId}|{$visitor->identityKey()}";
    }
}
