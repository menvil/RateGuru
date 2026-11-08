<?php

namespace App\Actions\Games\Concerns;

use App\Exceptions\Games\CannotManageGameException;
use App\Models\Concerns\LocksUsersInOrder;
use App\Models\Game;
use App\Models\GameContentSet;
use App\Models\GameFlow;
use App\Models\User;

/**
 * How every game write takes its locks, in one order: the administrator's
 * `users` row first (the project's order, docs/architecture/user-lifecycle.md),
 * then the game, then a content set, then a flow. A save of a set and the
 * publication of a version reading it therefore take turns instead of one
 * reading the other half-written.
 *
 * Everything is asked again on the locked rows: the administrator may still
 * manage games, and the row is still there at the version its editor was
 * drawn from, when the caller names one.
 */
trait LocksGameDraft
{
    use LocksUsersInOrder;

    /** @throws CannotManageGameException */
    private function lockAdministrator(User $admin): User
    {
        $locked = $this->lockUsersInOrder((int) $admin->getKey())->get($admin->getKey());

        if ($locked === null || ! $locked->can('manage-games')) {
            throw CannotManageGameException::becauseUserIsNotAllowed();
        }

        return $locked;
    }

    /** @throws CannotManageGameException */
    private function lockGame(int $gameId, ?int $expectedLockVersion = null): Game
    {
        $game = Game::query()->whereKey($gameId)->lockForUpdate()->first();

        if ($game === null) {
            throw CannotManageGameException::becauseItIsGone();
        }

        if ($expectedLockVersion !== null && $game->lock_version !== $expectedLockVersion) {
            throw CannotManageGameException::becauseItChanged();
        }

        return $game;
    }

    /** @throws CannotManageGameException */
    private function lockContentSet(Game $game, int $setId, ?int $expectedLockVersion = null): GameContentSet
    {
        $set = GameContentSet::query()->where('game_id', $game->id)->whereKey($setId)->lockForUpdate()->first();

        if ($set === null) {
            throw CannotManageGameException::becauseItIsGone('This content set');
        }

        if ($expectedLockVersion !== null && $set->lock_version !== $expectedLockVersion) {
            throw CannotManageGameException::becauseItChanged('this content set');
        }

        return $set;
    }

    /** @throws CannotManageGameException */
    private function lockFlow(Game $game, int $flowId, ?int $expectedLockVersion = null): GameFlow
    {
        $flow = GameFlow::query()->where('game_id', $game->id)->whereKey($flowId)->lockForUpdate()->first();

        if ($flow === null) {
            throw CannotManageGameException::becauseItIsGone('This flow');
        }

        if ($expectedLockVersion !== null && $flow->lock_version !== $expectedLockVersion) {
            throw CannotManageGameException::becauseItChanged('this flow');
        }

        return $flow;
    }

    /** Records who changed the game last, and moves its version on. */
    private function touchGame(Game $game, User $admin): void
    {
        $game->forceFill([
            'updated_by_user_id' => $admin->id,
            'lock_version' => $game->lock_version + 1,
        ])->save();
    }
}
