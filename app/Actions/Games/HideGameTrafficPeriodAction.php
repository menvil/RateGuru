<?php

namespace App\Actions\Games;

use App\Actions\Games\Concerns\LocksGameDraft;
use App\Exceptions\Games\CannotManageGameException;
use App\Models\GameTrafficPeriod;
use App\Models\User;
use App\Support\Observability\DomainLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Takes an ended traffic period out of Growth → Experiments, and puts it
 * back: Delete period there, and its Undo. Nothing of the period goes — its
 * shares, assignments and attempts stay, and the game's own tabs still list
 * it; Experiments just stops listing it and offering it in a period's
 * switcher. A period still open can't be hidden: it is where the visitors go
 * now. Who did it is a log line (games.period.hidden, games.period.shown);
 * asked again for what it already is, it changes nothing and logs nothing.
 */
final class HideGameTrafficPeriodAction
{
    use LocksGameDraft;

    public function __construct(private readonly DomainLogger $logger) {}

    /**
     * @return bool whether it was hidden now, rather than already
     *
     * @throws CannotManageGameException
     */
    public function hide(User $admin, GameTrafficPeriod $period): bool
    {
        return $this->change($admin, $period, true);
    }

    /**
     * @return bool whether it was shown again now, rather than already listed
     *
     * @throws CannotManageGameException
     */
    public function show(User $admin, GameTrafficPeriod $period): bool
    {
        return $this->change($admin, $period, false);
    }

    /** @throws CannotManageGameException */
    private function change(User $admin, GameTrafficPeriod $period, bool $hide): bool
    {
        if (! $admin->can('manage-games')) {
            throw CannotManageGameException::becauseUserIsNotAllowed();
        }

        $changed = DB::transaction(function () use ($admin, $period, $hide): bool {
            $this->lockAdministrator($admin);
            $locked = GameTrafficPeriod::query()->whereKey($period->id)->lockForUpdate()->first();

            if ($locked === null) {
                throw CannotManageGameException::becauseItIsGone('This period');
            }

            if ($hide && $locked->ended_at === null) {
                throw CannotManageGameException::because('Only an ended period can be deleted. This one is still where visitors go.');
            }

            if (($locked->hidden_at !== null) === $hide) {
                return false;
            }

            $locked->forceFill(['hidden_at' => $hide ? CarbonImmutable::now() : null])->save();

            return true;
        });

        if ($changed) {
            $this->logger->info($hide ? 'games.period.hidden' : 'games.period.shown', ['period_id' => $period->id, 'game_id' => $period->game_id, 'admin_id' => $admin->id]);
        }

        return $changed;
    }
}
