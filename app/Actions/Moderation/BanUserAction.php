<?php

namespace App\Actions\Moderation;

use App\Enums\ModerationActionType;
use App\Enums\UserStatus;
use App\Exceptions\Moderation\CannotModerateUserException;
use App\Models\User;

final class BanUserAction
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

        if ($target->isAdmin()) {
            throw CannotModerateUserException::becauseTargetIsProtected();
        }

        $oldStatus = $target->status;

        $target->forceFill([
            'status' => UserStatus::Banned,
        ])->save();

        $this->createModerationLog->handle(
            moderator: $admin,
            action: ModerationActionType::BanUser,
            target: $target,
            reason: $reason,
            metadata: [
                'from_status' => $oldStatus->value,
                'to_status' => UserStatus::Banned->value,
            ],
        );
    }
}
