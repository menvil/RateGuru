<?php

namespace App\Actions\Moderation;

use App\Enums\ModerationActionType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Exceptions\Moderation\CannotModerateUserException;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class MarkUserTrustedAction
{
    /**
     * Trust level at which a user is treated as "trusted" by the rest of
     * the system (see CreatePostAction). Marking promotes the user to
     * exactly this value.
     */
    public const TRUSTED_LEVEL = 10;

    public function __construct(
        private readonly CreateModerationLogAction $createModerationLog,
    ) {}

    public function handle(User $admin, User $target, ?string $reason = null): void
    {
        // Authorization (admin role + self protection + target-is-regular-user)
        // lives in UserPolicy::markTrusted. The in-transaction role/status
        // guards below remain as defence in depth and domain invariants.
        if (! $admin->can('markTrusted', $target)) {
            throw CannotModerateUserException::becauseUserIsNotAllowed();
        }

        DB::transaction(function () use ($admin, $target, $reason) {
            $locked = $target->newQuery()->lockForUpdate()->find($target->getKey());

            if ($locked === null || $locked->role !== UserRole::User) {
                throw CannotModerateUserException::becauseTargetIsProtected();
            }

            if ($locked->status !== UserStatus::Active) {
                throw CannotModerateUserException::becauseTargetStatusIsInvalid();
            }

            $oldTrustLevel = (int) $locked->trust_level;

            if ($oldTrustLevel >= self::TRUSTED_LEVEL) {
                throw CannotModerateUserException::becauseTargetStatusIsInvalid();
            }

            $persisted = $locked->forceFill([
                'trust_level' => self::TRUSTED_LEVEL,
            ])->save();

            if ($persisted !== true) {
                throw CannotModerateUserException::becauseTargetIsProtected();
            }

            $this->createModerationLog->handle(
                moderator: $admin,
                action: ModerationActionType::MarkUserTrusted,
                target: $locked,
                reason: $reason,
                metadata: [
                    'from_trust_level' => $oldTrustLevel,
                    'to_trust_level' => self::TRUSTED_LEVEL,
                ],
            );

            $target->setRawAttributes($locked->getAttributes(), true);
        });
    }
}
