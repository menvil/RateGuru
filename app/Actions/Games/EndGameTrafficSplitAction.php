<?php

namespace App\Actions\Games;

use App\Actions\Games\Concerns\LocksGameDraft;
use App\Exceptions\Games\CannotManageGameException;
use App\Models\Game;
use App\Models\GameTrafficPeriod;
use App\Models\User;
use App\Support\Observability\DomainLogger;
use Illuminate\Support\Facades\DB;

/**
 * Ends a game's split for a source or campaign: the period ends now, and the
 * paid clicks it covered go by the next split that covers them — the
 * source's, then the general one. Visitors it already gave a version keep it
 * while that version has a share in any open period of the game.
 *
 * The period stays, even one nobody was sent through yet: a visitor whose
 * page read it a moment ago may be writing their assignment into it now, and
 * a period deleted under them would fail that write.
 */
final class EndGameTrafficSplitAction
{
    use LocksGameDraft;

    public function __construct(private readonly DomainLogger $logger) {}

    /** @throws CannotManageGameException */
    public function handle(User $admin, Game $game, int $periodId): void
    {
        if (! $admin->can('manage-games')) {
            throw CannotManageGameException::becauseUserIsNotAllowed();
        }

        DB::transaction(function () use ($admin, $game, $periodId): void {
            $lockedAdmin = $this->lockAdministrator($admin);
            $locked = $this->lockGame((int) $game->id);
            $period = GameTrafficPeriod::query()
                ->where('game_id', $locked->id)
                ->whereKey($periodId)
                ->whereNotNull('source_id')
                ->whereNull('ended_at')
                ->lockForUpdate()
                ->first();

            if ($period === null) {
                throw CannotManageGameException::becauseItChanged('this split');
            }

            $period->forceFill(['ended_at' => now(), 'open_scope' => null])->save();
            $this->touchGame($locked, $lockedAdmin);
        });

        $this->logger->info('game.traffic.split_ended', ['game_id' => $game->id, 'period_id' => $periodId, 'admin_id' => $admin->id]);
    }
}
