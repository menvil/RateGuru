<?php

namespace App\Actions\Moderation;

use App\Enums\UserStatus;
use App\Exceptions\Moderation\CannotModerateUserException;
use App\Models\User;

final class ShadowbanUserAction
{
    public function handle(User $admin, User $target, ?string $reason = null): void
    {
        if (! $admin->isAdmin()) {
            throw CannotModerateUserException::becauseUserIsNotAllowed();
        }

        if ($admin->id === $target->id || $target->isAdmin()) {
            throw CannotModerateUserException::becauseTargetIsProtected();
        }

        $target->forceFill([
            'status' => UserStatus::Shadowbanned,
        ])->save();
    }
}
