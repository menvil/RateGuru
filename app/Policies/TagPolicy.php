<?php

namespace App\Policies;

use App\Models\Tag;
use App\Models\User;

class TagPolicy
{
    /**
     * Tags are the panel's to curate, moderators' as well as administrators':
     * visitors only choose among them when they upload. Role AND lifecycle,
     * as everywhere else in the panel: a sanctioned moderator or administrator
     * keeps the role and loses the capability until restored.
     */
    public function create(User $user): bool
    {
        return $this->canCurate($user);
    }

    public function update(User $user, Tag $tag): bool
    {
        return $this->canCurate($user);
    }

    /**
     * Deleting taxonomy is irreversible and has long-term consequences,
     * so it is restricted to admins. The "tag is still used by posts"
     * guard is a domain invariant enforced in DeleteTagAction, not here.
     */
    public function delete(User $user, Tag $tag): bool
    {
        return $user->isAdmin();
    }

    private function canCurate(User $user): bool
    {
        return ($user->isModerator() || $user->isAdmin())
            && $user->canAccessPrivilegedPanel();
    }
}
