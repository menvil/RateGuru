<?php

namespace App\Actions\Moderation\Concerns;

use App\Exceptions\Moderation\CannotModerateUserException;
use App\Models\Concerns\LocksUsersInOrder;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Shared locked-pair authorization for every moderation action targeting a
 * user: lock actor and target deterministically, then re-authorize on the
 * fresh rows — a stale request from a just-sanctioned or demoted admin
 * must fail even though its caller object still says Active Admin.
 */
trait LocksAndAuthorizesModerationPair
{
    use LocksUsersInOrder;

    /** @return array{User, User} the locked [actor, target] */
    private function lockAndAuthorizePair(User $admin, User $target, string $ability): array
    {
        $locked = $this->lockUsersInOrder((int) $admin->getKey(), (int) $target->getKey());

        $lockedActor = $locked->get($admin->getKey());
        $lockedTarget = $locked->get($target->getKey());

        if ($lockedActor === null || $lockedTarget === null) {
            throw CannotModerateUserException::becauseUserIsNotAllowed();
        }

        if (! Gate::forUser($lockedActor)->allows($ability, $lockedTarget)) {
            throw CannotModerateUserException::becauseUserIsNotAllowed();
        }

        return [$lockedActor, $lockedTarget];
    }
}
