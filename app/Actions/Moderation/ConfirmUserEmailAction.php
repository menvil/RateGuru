<?php

namespace App\Actions\Moderation;

use App\Actions\Moderation\Concerns\LocksAndAuthorizesModerationPair;
use App\Enums\ModerationActionType;
use App\Enums\UserStatus;
use App\Exceptions\Moderation\CannotModerateUserException;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\DB;

/**
 * An administrator vouches for a member's address when the confirmation mail
 * cannot reach them. It counts as any other confirmation: the member takes
 * part, and the cleanup of unconfirmed accounts no longer removes them.
 */
final class ConfirmUserEmailAction
{
    use LocksAndAuthorizesModerationPair;

    public function __construct(
        private readonly CreateModerationLogAction $createModerationLog,
    ) {}

    /** @param  string  $email  the address the administrator was shown, and is vouching for */
    public function handle(User $admin, User $target, string $email, ?string $reason = null): void
    {
        if (! $admin->can('confirmEmail', $target)) {
            throw CannotModerateUserException::becauseUserIsNotAllowed();
        }

        DB::transaction(function () use ($admin, $target, $email, $reason): void {
            [$lockedActor, $locked] = $this->lockAndAuthorizePair($admin, $target, 'confirmEmail');

            // On the locked row: an address changed since the dialog opened is
            // one nobody looked at, and could be someone else's.
            if ($locked->status !== UserStatus::Active || $locked->hasVerifiedEmail() || $locked->email !== $email) {
                throw CannotModerateUserException::becauseTargetStatusIsInvalid();
            }

            if (! $locked->markEmailAsVerified()) {
                throw CannotModerateUserException::becauseTargetIsProtected();
            }

            $this->createModerationLog->handle(
                moderator: $lockedActor,
                action: ModerationActionType::ConfirmUserEmail,
                target: $locked,
                reason: $reason,
            );

            $target->setRawAttributes($locked->getAttributes(), true);
        });

        // Only after the confirmation committed, as for a link.
        event(new Verified($target));
    }
}
