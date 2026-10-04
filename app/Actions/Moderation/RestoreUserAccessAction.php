<?php

namespace App\Actions\Moderation;

use App\Actions\Moderation\Concerns\ExecutesUserStatusTransition;
use App\Enums\ModerationActionType;
use App\Enums\UserStatus;
use App\Models\User;

/**
 * The single restore path for every living sanction:
 * Limited/Banned/Shadowbanned -> Active. Replaces the old UnbanUserAction
 * (whose historical UnbanUser log entries remain hydratable). Restoring
 * touches only users.status: role, trust_level, content and the social
 * graph were never changed by the sanction, so Active behavior returns
 * naturally (docs/architecture/user-lifecycle.md).
 */
final class RestoreUserAccessAction
{
    use ExecutesUserStatusTransition;

    public function __construct(
        private readonly CreateModerationLogAction $createModerationLog,
    ) {}

    public function handle(User $admin, User $target, ?string $reason = null): void
    {
        $this->executeTransition(
            admin: $admin,
            target: $target,
            reason: $reason,
            ability: 'restoreAccess',
            validSourceStatuses: [UserStatus::Limited, UserStatus::Banned, UserStatus::Shadowbanned],
            toStatus: UserStatus::Active,
            logAction: ModerationActionType::RestoreUserAccess,
        );
    }
}
