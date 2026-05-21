<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

class UserPolicy
{
    public function manage(User $actor, User $target): bool
    {
        return $actor->role === UserRole::Admin
            && $actor->id !== $target->id
            && $target->role !== UserRole::Admin;
    }

    public function ban(User $actor, User $target): bool
    {
        return $this->canSanction($actor, $target);
    }

    public function unban(User $actor, User $target): bool
    {
        return $this->canSanction($actor, $target);
    }

    public function shadowban(User $actor, User $target): bool
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
            && $actor->id !== $target->id
            && $target->role === UserRole::User;
    }

    public function viewAdmin(User $actor): bool
    {
        return in_array($actor->role, [UserRole::Admin, UserRole::Moderator], true);
    }

    /**
     * Admins may sanction (ban/unban/shadowban) any non-admin user other
     * than themselves. Mirrors the authorization guards previously inlined
     * in the moderation actions.
     */
    private function canSanction(User $actor, User $target): bool
    {
        return $actor->role === UserRole::Admin
            && $actor->id !== $target->id
            && $target->role !== UserRole::Admin;
    }
}
