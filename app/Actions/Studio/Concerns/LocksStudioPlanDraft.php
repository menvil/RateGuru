<?php

namespace App\Actions\Studio\Concerns;

use App\Exceptions\Studio\CannotManageStudioPlanException;
use App\Models\Concerns\LocksUsersInOrder;
use App\Models\StudioPlan;
use App\Models\User;

/**
 * How every Content Studio write to a plan takes its locks, in the project's
 * order (docs/architecture/user-lifecycle.md): the `users` rows first — the
 * administrator's and, when one is chosen, the author's, in ascending id
 * order (LocksUsersInOrder), the order a sanction of that Persona takes — then
 * the plan. Nothing locks a user once the plan is held: whichever users a
 * write locks are known before it starts, and a plan changed meanwhile is
 * refused by its version rather than followed.
 *
 * Everything is asked again on the locked rows, never on the caller's copies:
 * the administrator may still manage plans, the author is still a Persona
 * that is not deleted, and the plan is still there, still a Draft, and still
 * the version the editor was drawn from.
 */
trait LocksStudioPlanDraft
{
    use LocksUsersInOrder;

    /**
     * @return array{0: User, 1: User|null} the locked administrator, and the locked author when one is chosen
     *
     * @throws CannotManageStudioPlanException
     */
    private function lockAdministratorAndAuthor(User $admin, ?int $authorId = null): array
    {
        $rows = $this->lockUsersInOrder((int) $admin->getKey(), ...($authorId !== null ? [$authorId] : []));
        $lockedAdmin = $rows->get($admin->getKey());

        if ($lockedAdmin === null || ! $lockedAdmin->can('manage-studio-plans')) {
            throw CannotManageStudioPlanException::becauseUserIsNotAllowed();
        }

        if ($authorId === null) {
            return [$lockedAdmin, null];
        }

        // The role and the status that count are the ones the row has now.
        $author = $rows->get($authorId);

        if ($author === null || ! $author->isPersona() || $author->isTombstoned()) {
            throw CannotManageStudioPlanException::becauseAuthorIsUnavailable();
        }

        return [$lockedAdmin, $author];
    }

    /**
     * The plan as it is now — locked, once the users are — when it is still a
     * Draft at the version the editor was drawn from.
     *
     * @throws CannotManageStudioPlanException
     */
    private function currentDraft(int $planId, int $expectedLockVersion, bool $lock = true): StudioPlan
    {
        $query = StudioPlan::query()->whereKey($planId);
        $plan = ($lock ? $query->lockForUpdate() : $query)->first();

        if ($plan === null) {
            throw CannotManageStudioPlanException::becausePlanIsGone();
        }

        if (! $plan->isDraft()) {
            throw CannotManageStudioPlanException::becausePlanIsNotDraft();
        }

        if ($plan->lock_version !== $expectedLockVersion) {
            throw CannotManageStudioPlanException::becausePlanChanged();
        }

        return $plan;
    }
}
