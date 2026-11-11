<?php

namespace App\Actions\Games;

use App\Actions\Games\Concerns\LocksGameDraft;
use App\Enums\GameVersionStatus;
use App\Exceptions\Games\CannotManageGameException;
use App\Models\GameTrafficPeriod;
use App\Models\GameVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Archives a published version, so it can take no more traffic, or restores
 * it. A version with a share in a current period is archived only once Traffic
 * has taken the share away. Nothing of the version changes but its status:
 * its snapshot, its attempts and its numbers stay.
 */
final class ChangeGameVersionAction
{
    use LocksGameDraft;

    /** @throws CannotManageGameException */
    public function retire(User $admin, GameVersion $version): void
    {
        $this->change($admin, $version, function (GameVersion $locked): void {
            $inTraffic = GameTrafficPeriod::query()
                ->where('game_id', $locked->game_id)
                ->whereNull('ended_at')
                ->whereHas('versions', fn ($versions) => $versions->whereKey($locked->id))
                ->exists();

            if ($inTraffic) {
                throw CannotManageGameException::because('This version still has a share of traffic. Remove it in Traffic first.');
            }

            $locked->forceFill(['status' => GameVersionStatus::Retired, 'retired_at' => now()])->save();
        });
    }

    /** @throws CannotManageGameException */
    public function restore(User $admin, GameVersion $version): void
    {
        $this->change($admin, $version, fn (GameVersion $locked) => $locked->forceFill(['status' => GameVersionStatus::Active, 'retired_at' => null])->save());
    }

    /**
     * @param  callable(GameVersion): mixed  $change
     *
     * @throws CannotManageGameException
     */
    private function change(User $admin, GameVersion $version, callable $change): void
    {
        if (! $admin->can('manage-games')) {
            throw CannotManageGameException::becauseUserIsNotAllowed();
        }

        DB::transaction(function () use ($admin, $version, $change): void {
            $lockedAdmin = $this->lockAdministrator($admin);
            $game = $this->lockGame((int) $version->game_id);
            $locked = GameVersion::query()->where('game_id', $game->id)->whereKey($version->id)->lockForUpdate()->first();

            if ($locked === null) {
                throw CannotManageGameException::becauseItIsGone('This version');
            }

            $change($locked);
            $this->touchGame($game, $lockedAdmin);
            $version->setRawAttributes($locked->getAttributes(), true);
        });
    }
}
