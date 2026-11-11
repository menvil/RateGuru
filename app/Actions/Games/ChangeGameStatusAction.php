<?php

namespace App\Actions\Games;

use App\Actions\Games\Concerns\LocksGameDraft;
use App\Enums\GameStatus;
use App\Exceptions\Games\CannotManageGameException;
use App\Models\Game;
use App\Models\User;
use App\Queries\Games\GameTrafficQuery;
use App\Support\Observability\DomainLogger;
use Illuminate\Support\Facades\DB;

/**
 * Moves a game between its states:
 *
 * - Live, from Draft or Paused, only while a published version that can be
 *   played has a share of traffic in at least one audience;
 * - Paused, from Live: no new attempts; started ones finish; shares and
 *   periods are kept;
 * - Archived, from any other state: over, hidden from visitors, every number
 *   kept;
 * - out of Archived, back to Paused — or to Draft for a game never published.
 *
 * Nothing is ever deleted: attempts, versions and periods stay.
 */
final class ChangeGameStatusAction
{
    use LocksGameDraft;

    public function __construct(
        private readonly GameTrafficQuery $traffic,
        private readonly DomainLogger $logger,
    ) {}

    /** @throws CannotManageGameException */
    public function handle(User $admin, Game $game, GameStatus $target): void
    {
        if (! $admin->can('manage-games')) {
            throw CannotManageGameException::becauseUserIsNotAllowed();
        }

        $from = DB::transaction(function () use ($admin, $game, $target): GameStatus {
            $lockedAdmin = $this->lockAdministrator($admin);
            $locked = $this->lockGame((int) $game->id);
            $from = $locked->status;

            if ($from === $target) {
                return $from;
            }

            $allowed = match ($target) {
                GameStatus::Live => in_array($from, [GameStatus::Draft, GameStatus::Paused], true),
                GameStatus::Paused => $from === GameStatus::Live || ($from === GameStatus::Archived && $locked->hasBeenPublished()),
                GameStatus::Archived => true,
                GameStatus::Draft => $from === GameStatus::Archived && ! $locked->hasBeenPublished(),
            };

            if (! $allowed) {
                throw CannotManageGameException::because(self::refusal($from, $target));
            }

            if ($target === GameStatus::Live && ! $this->traffic->hasPlayableTraffic((int) $locked->id)) {
                throw CannotManageGameException::because($locked->hasBeenPublished()
                    ? 'Give a version a share in Traffic first.'
                    : 'Publish a version and give it a share in Traffic first.');
            }

            $locked->forceFill(['status' => $target, 'status_changed_at' => now()]);
            $this->touchGame($locked, $lockedAdmin);
            $game->setRawAttributes($locked->getAttributes(), true);

            return $from;
        });

        // A move to where the game already stands changed nothing, so it is no change to record.
        if ($from !== $target) {
            $this->logger->info('game.status.changed', ['game_id' => $game->id, 'admin_id' => $admin->id, 'from' => $from->value, 'to' => $target->value]);
        }
    }

    private static function refusal(GameStatus $from, GameStatus $target): string
    {
        return match ($target) {
            GameStatus::Paused => 'Only a live game can be paused.',
            GameStatus::Draft => 'A game that has been published goes back to Paused, never to Draft.',
            // Only an archived game is refused going live, and it comes back by Restore.
            GameStatus::Live => "A game that is {$from->label()} can’t go live. Restore it first.",
            default => "A game that is {$from->label()} can’t become {$target->label()}.",
        };
    }
}
