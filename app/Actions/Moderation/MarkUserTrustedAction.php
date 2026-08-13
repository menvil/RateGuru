<?php

namespace App\Actions\Moderation;

use App\Enums\ModerationActionType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Exceptions\Moderation\CannotModerateUserException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

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
            // Same deterministic ascending-id pair lock as the lifecycle
            // sanctions: the actor is re-authorized on its fresh row so a
            // just-sanctioned admin cannot finish a stale trust promotion.
            $lockedPair = User::query()
                ->whereIn('id', [$admin->getKey(), $target->getKey()])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $lockedActor = $lockedPair->get($admin->getKey());
            $locked = $lockedPair->get($target->getKey());

            if ($lockedActor === null || $locked === null) {
                throw CannotModerateUserException::becauseUserIsNotAllowed();
            }

            if (! Gate::forUser($lockedActor)->allows('markTrusted', $locked)) {
                throw CannotModerateUserException::becauseUserIsNotAllowed();
            }

            if ($locked->role !== UserRole::User) {
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
                moderator: $lockedActor,
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
