<?php

namespace App\Actions\Moderation;

use App\Actions\Moderation\Concerns\ExecutesUserStatusTransition;
use App\Enums\ModerationActionType;
use App\Enums\UserStatus;
use App\Models\User;

/**
 * Manually reversible participation restriction — the mildest living
 * sanction. Only an Active account can be limited; escalation from
 * Limited goes to Ban/Shadowban, never the other way around
 * (docs/architecture/user-lifecycle.md).
 */
final class LimitUserAction
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
            ability: 'limit',
            validSourceStatuses: [UserStatus::Active],
            toStatus: UserStatus::Limited,
            logAction: ModerationActionType::LimitUser,
        );
    }
}
