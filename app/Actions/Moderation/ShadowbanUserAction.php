<?php

namespace App\Actions\Moderation;

use App\Actions\Moderation\Concerns\ExecutesUserStatusTransition;
use App\Enums\ModerationActionType;
use App\Enums\UserStatus;
use App\Models\User;

/**
 * Moderation-facing restriction label. Capability-wise identical to
 * Limited/Banned (no fake viewer-dependent shadow visibility): existing
 * content stays publicly visible and only participation is blocked.
 * Banned is deliberately not a valid source — downgrading a ban requires
 * an explicit restore to Active first (docs/architecture/user-lifecycle.md).
 */
final class ShadowbanUserAction
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
            ability: 'shadowban',
            validSourceStatuses: [UserStatus::Active, UserStatus::Limited],
            toStatus: UserStatus::Shadowbanned,
            logAction: ModerationActionType::ShadowbanUser,
        );
    }
}
