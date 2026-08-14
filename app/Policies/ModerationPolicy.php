<?php

namespace App\Policies;

use App\Models\User;

class ModerationPolicy
{
    /**
     * Content moderation requires role AND lifecycle eligibility (PR-F): a
     * sanctioned moderator keeps role=Moderator but loses every privileged
     * capability until restored to Active.
     */
    public function moderateContent(User $user): bool
    {
        return ($user->isModerator() || $user->isAdmin())
            && $user->canAccessPrivilegedPanel();
    }

    public function banUser(User $user): bool
    {
        return $user->isAdmin()
            && $user->canAccessPrivilegedPanel();
    }
}
