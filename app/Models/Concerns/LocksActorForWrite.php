<?php

namespace App\Models\Concerns;

use App\Models\User;

/**
 * Authoritative actor re-read for lifecycle-dependent writes
 * (docs/architecture/user-lifecycle.md). A capability check on the
 * caller's User instance is never enough: an admin sanction can commit
 * between the pre-check and the write, leaving a stale Active object.
 * Every participation action re-reads and locks the actor row FIRST —
 * the uniform lock order is Actor User -> other User rows (ascending id)
 * -> Post -> Comment/RatingGroup/child rows -> edges.
 *
 * Returns null when the row is gone; callers fail closed.
 */
trait LocksActorForWrite
{
    private function lockActor(User $actor): ?User
    {
        return User::query()
            ->whereKey($actor->getKey())
            ->lockForUpdate()
            ->first();
    }
}
