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
        if (! $admin->isAdmin()) {
            throw CannotModerateUserException::becauseUserIsNotAllowed();
        }

        // Re-read the target status under a row lock so a concurrent
        // status change cannot desync the recorded from_status from
        // what is actually mutated.
        DB::transaction(function () use ($admin, $target, $reason) {
            $locked = $target->newQuery()->lockForUpdate()->find($target->getKey());

            if ($locked === null) {
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
