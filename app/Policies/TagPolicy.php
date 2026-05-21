<?php

namespace App\Policies;

use App\Models\Tag;
use App\Models\User;

class TagPolicy
{
    /**
     * Deleting taxonomy is irreversible and has long-term consequences,
     * so it is restricted to admins. The "tag is still used by posts"
     * guard is a domain invariant enforced in DeleteTagAction, not here.
     */
    public function delete(User $user, Tag $tag): bool
    {
        return $user->isAdmin();
    }
}
