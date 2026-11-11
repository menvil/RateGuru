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
 * A live game keeps playable traffic in at least one audience: taking the
 * last of it away, or handing it all to versions that can't be played, asks
 * for the game to be paused first.
 *
 * Traffic changes by other ways too — a version published taking over
 * another's shares — so a caller that drew the shares it changes names them:
 * when the audience's shares are no longer those, nothing is applied.
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
     * @param  array<int, int>|null  $expected  the audience's shares the caller drew, version id => share
     * @return bool whether a new period started
     *
     * @throws CannotManageGameException
     */
    public function handle(User $admin, Game $game, GameAudience $audience, array $shares, ?array $expected = null): bool
    {
        if (! $admin->can('manage-games')) {
            throw CannotManageGameException::becauseUserIsNotAllowed();
        }

        $started = DB::transaction(function () use ($admin, $game, $audience, $shares, $expected): bool {
            $lockedAdmin = $this->lockAdministrator($admin);
            $locked = $this->lockGame((int) $game->id);

            if ($expected !== null) {
                $current = $this->traffic->currentPeriod((int) $locked->id, $audience)?->shares() ?? [];
                ksort($current);
                ksort($expected);

                if ($current !== $expected) {
                    throw CannotManageGameException::becauseItChanged('this game’s traffic');
                }
            }

            $assignable = GameVersion::query()->where('game_id', $locked->id)->where('status', GameVersionStatus::Active)->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
            $issues = TrafficShareValidator::issues($shares, $assignable);

            if ($issues !== []) {
                throw CannotManageGameException::because($issues[0]);
            }

            $requested = array_keys($shares);
            $playable = array_diff($requested, $this->traffic->unplayableVersionIds($requested));

            if ($locked->status === GameStatus::Live && $playable === []) {
                $other = $audience === GameAudience::Anonymous ? GameAudience::Authenticated : GameAudience::Anonymous;

                if ($this->traffic->playableShares((int) $locked->id, $other) === []) {
                    throw CannotManageGameException::because($shares === []
                        ? 'A live game needs traffic in at least one audience. Pause the game first.'
                        : 'None of these versions can be played, and a live game needs traffic in at least one audience. Pause the game first.');
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
