<?php

namespace App\Actions\Moderation;

use App\Enums\ModerationActionType;
use App\Enums\UserStatus;
use App\Exceptions\Moderation\CannotModerateUserException;
use App\Models\User;

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

        if ($admin->id === $target->id || $target->isAdmin()) {
            throw CannotModerateUserException::becauseTargetIsProtected();
        }

        $oldStatus = $target->status;

        $target->forceFill([
            'status' => UserStatus::Shadowbanned,
        ])->save();

        $this->createModerationLog->handle(
            moderator: $admin,
            action: ModerationActionType::ShadowbanUser,
            target: $target,
            reason: $reason,
            metadata: [
                'from_status' => $oldStatus->value,
                'to_status' => UserStatus::Shadowbanned->value,
            ],
        );
    }
}
