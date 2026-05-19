<?php

namespace App\Actions\Moderation;

use App\Enums\ModerationActionType;
use App\Enums\UserStatus;
use App\Exceptions\Moderation\CannotModerateUserException;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class ShadowbanUserAction
{
    public function __construct(
        private readonly CreateModerationLogAction $createModerationLog,
    ) {}

    public function handle(User $admin, User $target, ?string $reason = null): void
    {
        if (! $admin->isAdmin()) {
            throw CannotModerateUserException::becauseUserIsNotAllowed();
        }

        if ($admin->id === $target->id) {
            throw CannotModerateUserException::becauseTargetIsProtected();
        }

        // Re-read the target role/status under a row lock so a concurrent
        // role change cannot slip past the protection check or desync
        // the recorded from_status from what is actually mutated.
        DB::transaction(function () use ($admin, $target, $reason) {
            $locked = $target->newQuery()->lockForUpdate()->find($target->getKey());

            if ($locked === null || $locked->isAdmin()) {
                throw CannotModerateUserException::becauseTargetIsProtected();
            }

            if ($locked->status === UserStatus::Shadowbanned) {
                throw CannotModerateUserException::becauseTargetStatusIsInvalid();
            }

            $oldStatus = $locked->status;

            $persisted = $locked->forceFill([
                'status' => UserStatus::Shadowbanned,
            ])->save();

            if ($persisted !== true) {
                throw CannotModerateUserException::becauseTargetIsProtected();
            }

            $this->createModerationLog->handle(
                moderator: $admin,
                action: ModerationActionType::ShadowbanUser,
                target: $locked,
                reason: $reason,
                metadata: [
                    'from_status' => $oldStatus->value,
                    'to_status' => UserStatus::Shadowbanned->value,
                ],
            );

            $target->setRawAttributes($locked->getAttributes(), true);
        });
    }
}
