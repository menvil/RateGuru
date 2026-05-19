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

        if ($admin->id === $target->id || $target->isAdmin()) {
            throw CannotModerateUserException::becauseTargetIsProtected();
        }

        $oldStatus = $target->status;

        // The status change and its audit log must be atomic: a shadowbanned
        // user must never exist without the matching moderation log.
        DB::transaction(function () use ($admin, $target, $reason, $oldStatus) {
            $persisted = $target->forceFill([
                'status' => UserStatus::Shadowbanned,
            ])->save();

            if ($persisted !== true) {
                throw CannotModerateUserException::becauseTargetIsProtected();
            }

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
        });
    }
}
