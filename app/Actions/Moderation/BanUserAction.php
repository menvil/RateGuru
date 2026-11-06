<?php

namespace App\Actions\Moderation;

use App\Actions\Moderation\Concerns\ExecutesUserStatusTransition;
use App\Enums\ModerationActionType;
use App\Enums\UserStatus;
use App\Models\User;

/**
 * Indefinite, manually reversible participation ban. Non-destructive: only
 * users.status changes and one ModerationLog is written — identity,
 * content, votes, follows, saves and notifications all remain
 * (docs/architecture/user-lifecycle.md).
 */
final class BanUserAction
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
            ability: 'ban',
            validSourceStatuses: [UserStatus::Active, UserStatus::Limited, UserStatus::Shadowbanned],
            toStatus: UserStatus::Banned,
            logAction: ModerationActionType::BanUser,
        );
    }
}
