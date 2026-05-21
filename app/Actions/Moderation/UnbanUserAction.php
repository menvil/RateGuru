<?php

namespace App\Actions\Moderation;

use App\Enums\ModerationActionType;
use App\Enums\UserStatus;
use App\Exceptions\Moderation\CannotModerateUserException;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class UnbanUserAction
{
    public function __construct(
        private readonly CreateModerationLogAction $createModerationLog,
    ) {}

    public function handle(User $admin, User $target, ?string $reason = null): void
    {
        // Authorization (admin role + self / target-admin protection) lives
        // in UserPolicy::unban. The in-transaction admin guard below remains
        // as defence in depth against concurrent role changes.
        if (! $admin->can('unban', $target)) {
            throw CannotModerateUserException::becauseUserIsNotAllowed();
        }

        // Re-read the target status under a row lock so a concurrent
        // status change cannot desync the recorded from_status from
        // what is actually mutated.
        DB::transaction(function () use ($admin, $target, $reason) {
            $locked = $target->newQuery()->lockForUpdate()->find($target->getKey());

            // Defence in depth: ban/shadowban actions already refuse to
            // sanction admin targets, so an admin should never appear here.
            // Guard anyway in case schema drift or a manual DB edit left
            // an admin in a banned/shadowbanned state.
            if ($locked === null || $locked->isAdmin()) {
                throw CannotModerateUserException::becauseTargetIsProtected();
            }

            if (! in_array($locked->status, [UserStatus::Banned, UserStatus::Shadowbanned], true)) {
                throw CannotModerateUserException::becauseTargetStatusIsInvalid();
            }

            $oldStatus = $locked->status;

            $persisted = $locked->forceFill([
                'status' => UserStatus::Active,
            ])->save();

            if ($persisted !== true) {
                throw CannotModerateUserException::becauseTargetIsProtected();
            }

            $this->createModerationLog->handle(
                moderator: $admin,
                action: ModerationActionType::UnbanUser,
                target: $locked,
                reason: $reason,
                metadata: [
                    'from_status' => $oldStatus->value,
                    'to_status' => UserStatus::Active->value,
                ],
            );

            $target->setRawAttributes($locked->getAttributes(), true);
        });
    }
}
