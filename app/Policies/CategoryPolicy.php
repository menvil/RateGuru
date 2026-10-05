<?php

namespace App\Policies;

use App\Models\Category;
use App\Models\User;

class CategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canAdminister($user);
    }

    public function view(User $user, Category $category): bool
    {
        return $this->canAdminister($user);
    }

    public function create(User $user): bool
    {
        return $this->canAdminister($user);
    }

    public function update(User $user, Category $category): bool
    {
        return $this->canAdminister($user);
    }

    public function delete(User $user, Category $category): bool
    {
        return $this->canAdminister($user);
    }

    /**
     * Role AND lifecycle, the same pair every other privileged policy here
     * applies: a sanctioned administrator keeps the role and loses the
     * capability until they are restored.
     *
     * Checking only isAdmin() made the lifecycle invisible to this policy, and
     * the consequence was not theoretical: DeleteCategoryAction re-reads and
     * locks the actor precisely because a sanction can commit between the
     * pre-check and the write — and a sanction changes STATUS, which this policy
     * then ignored. The re-authorization could only ever catch a role change,
     * which is the less likely of the two.
     */
    private function canAdminister(User $user): bool
    {
        return $user->isAdmin() && $user->canAccessPrivilegedPanel();
    }
}
