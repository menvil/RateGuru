<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;

class UserPolicy
{
    public function manage(User $actor, User $target): bool
    {
        return $this->canSanction($actor, $target);
    }

    public function ban(User $actor, User $target): bool
    {
        return $this->canSanction($actor, $target);
    }

    public function limit(User $actor, User $target): bool
    {
        return $this->canSanction($actor, $target);
    }

    public function shadowban(User $actor, User $target): bool
    {
        return $this->canSanction($actor, $target);
    }

    /**
     * Restores any living sanction (Limited/Banned/Shadowbanned) to Active.
     * Replaces the old `unban` ability together with UnbanUserAction.
     */
    public function restoreAccess(User $actor, User $target): bool
    {
        return $this->canSanction($actor, $target);
    }

    /**
     * Only regular users can be promoted to trusted; moderators and admins
     * are excluded. State preconditions (active status, current trust level)
     * remain domain invariants enforced in MarkUserTrustedAction.
     */
    public function markTrusted(User $actor, User $target): bool
    {
        return $actor->role === UserRole::Admin
            && $actor->status === UserStatus::Active
            && $actor->id !== $target->id
            && $target->role === UserRole::User
            && ! $target->isTombstoned();
    }

    public function viewAdmin(User $actor): bool
    {
        return in_array($actor->role, [UserRole::Admin, UserRole::Moderator], true);
    }

    /**
     * Only an ACTIVE admin may sanction (limit/ban/shadowban/restore) any
     * non-admin user other than themselves — a sanctioned admin loses the
     * ability along with panel access, and the moderation actions
     * re-evaluate this policy against the freshly locked actor row so a
     * stale request cannot slip through. Deleted tombstones are out of
     * reach for every ordinary admin operation (manage/edit included): an
     * anonymized account must never be re-identified or reactivated.
     */
    private function canSanction(User $actor, User $target): bool
    {
        return $actor->role === UserRole::Admin
            && $actor->status === UserStatus::Active
            && $actor->id !== $target->id
            && $target->role !== UserRole::Admin
            && ! $target->isTombstoned();
    }
}
