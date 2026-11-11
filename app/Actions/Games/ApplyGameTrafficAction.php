<?php

namespace App\Actions\Games;

use App\Actions\Games\Concerns\AppliesTrafficShares;
use App\Actions\Games\Concerns\LocksGameDraft;
use App\Enums\GameAudience;
use App\Enums\GameStatus;
use App\Enums\GameVersionStatus;
use App\Exceptions\Games\CannotManageGameException;
use App\Models\Game;
use App\Models\GameVersion;
use App\Models\User;
use App\Queries\Games\GameTrafficQuery;
use App\Support\Games\Traffic\TrafficShareValidator;
use App\Support\Observability\DomainLogger;
use Illuminate\Support\Facades\DB;

/**
 * Applies an audience's shares of a game's traffic: whole percentages of
 * active versions adding up to 100, or none at all — an explicit "no
 * traffic". Visitors already given a version keep it while it still has a
 * share; attempts already started stay on theirs whatever the shares.
 *
 * A live game keeps traffic in at least one audience: taking the last of it
 * away asks for the game to be paused first.
 */
final class ApplyGameTrafficAction
{
    use AppliesTrafficShares;
    use LocksGameDraft;

    public function __construct(
        private readonly GameTrafficQuery $traffic,
        private readonly DomainLogger $logger,
    ) {}

    /**
     * @param  array<int, int>  $shares  version id => share
     * @return bool whether a new period started
     *
     * @throws CannotManageGameException
     */
    public function handle(User $admin, Game $game, GameAudience $audience, array $shares): bool
    {
        if (! $admin->can('manage-games')) {
            throw CannotManageGameException::becauseUserIsNotAllowed();
        }

        $started = DB::transaction(function () use ($admin, $game, $audience, $shares): bool {
            $lockedAdmin = $this->lockAdministrator($admin);
            $locked = $this->lockGame((int) $game->id);
            $assignable = GameVersion::query()->where('game_id', $locked->id)->where('status', GameVersionStatus::Active)->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
            $issues = TrafficShareValidator::issues($shares, $assignable);

            if ($issues !== []) {
                throw CannotManageGameException::because($issues[0]);
            }

            if ($locked->status === GameStatus::Live && $shares === []) {
                $other = $audience === GameAudience::Anonymous ? GameAudience::Authenticated : GameAudience::Anonymous;

                if ($this->traffic->playableShares((int) $locked->id, $other) === []) {
                    throw CannotManageGameException::because('A live game needs traffic in at least one audience. Pause the game first.');
                }
            }

            ksort($shares);
            $started = $this->applyShares($locked, $audience, $shares, $lockedAdmin);
            $this->touchGame($locked, $lockedAdmin);

            return $started;
        });

        $this->logger->info('game.traffic.applied', ['game_id' => $game->id, 'audience' => $audience->value, 'shares' => $shares, 'admin_id' => $admin->id]);

        return $started;
    }
}
