<?php

namespace App\Actions\Moderation\Concerns;

use App\Enums\ModerationActionType;
use App\Enums\UserStatus;
use App\Exceptions\Moderation\CannotModerateUserException;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Shared executor for every user lifecycle sanction/restore
 * (docs/architecture/user-lifecycle.md). One transaction:
 *
 * 1. Lock BOTH the acting admin and the target, one row at a time in
 *    ascending primary-key order (LocksUsersInOrder) — formally the same
 *    global acquisition order for every code path locking user rows, so
 *    opposite-order deadlocks cannot occur.
 * 2. Re-authorize on the fresh locked rows (Gate::forUser): a stale
 *    request from an admin who was just sanctioned or demoted must fail
 *    even though its caller object still says Active Admin.
 * 3. Validate the transition matrix against the locked target status —
 *    invalid or same-state transitions throw and log nothing.
 * 4. Mutate status, write exactly one ModerationLog with the authoritative
 *    from_status, sync the caller's model.
 */
trait ExecutesUserStatusTransition
{
    use LocksAndAuthorizesModerationPair;

    /** @param list<UserStatus> $validSourceStatuses */
    private function executeTransition(
        User $admin,
        User $target,
        ?string $reason,
        string $ability,
        array $validSourceStatuses,
        UserStatus $toStatus,
        ModerationActionType $logAction,
    ): void {
        // Cheap pre-check only; the locked re-check below is authoritative.
        if (! $admin->can($ability, $target)) {
            throw CannotModerateUserException::becauseUserIsNotAllowed();
        }

        DB::transaction(function () use ($admin, $target, $reason, $ability, $validSourceStatuses, $toStatus, $logAction): void {
            [$lockedActor, $lockedTarget] = $this->lockAndAuthorizePair($admin, $target, $ability);

            if (! in_array($lockedTarget->status, $validSourceStatuses, true)) {
                throw CannotModerateUserException::becauseTargetStatusIsInvalid();
            }

            $fromStatus = $lockedTarget->status;

            $persisted = $lockedTarget->forceFill([
                'status' => $toStatus,
            ])->save();

            if ($persisted !== true) {
                throw CannotModerateUserException::becauseTargetIsProtected();
            }

            $this->createModerationLog->handle(
                moderator: $lockedActor,
                action: $logAction,
                target: $lockedTarget,
                reason: $reason,
                metadata: [
                    'from_status' => $fromStatus->value,
                    'to_status' => $toStatus->value,
                ],
            );

            $target->setRawAttributes($lockedTarget->getAttributes(), true);
        });
    }
}
