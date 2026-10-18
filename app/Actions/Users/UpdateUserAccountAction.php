<?php

namespace App\Actions\Users;

use App\Actions\Moderation\Concerns\LocksAndAuthorizesModerationPair;
use App\Enums\UserRole;
use App\Exceptions\Moderation\CannotModerateUserException;
use App\Exceptions\Users\CannotUpdateUserAccountException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * An administrator's edit of someone's account: their name, username and
 * email address, their role, whether they are trusted, and a new password.
 * Never their lifecycle status — that moves only through the moderation
 * actions, which log it.
 *
 * Both accounts are locked and the administrator re-authorized on the fresh
 * rows (UserPolicy::manage), so a just-demoted administrator, or an account
 * deleted meanwhile, is never written. A new email address has to be
 * confirmed again, as when people change their own.
 *
 * Never a Persona, in either direction: a Persona is created and edited only
 * through Content Studio, so this edit neither makes one nor turns one back
 * into a person, and leaves everything of one alone — its synthetic address,
 * a password it must never have, its trust and its profile.
 */
final class UpdateUserAccountAction
{
    use LocksAndAuthorizesModerationPair;

    /**
     * @param  array{name: string, username: string, email: string, role: UserRole, trust_level: int, password?: string|null}  $changes
     */
    public function handle(User $admin, User $target, array $changes): void
    {
        if (! $admin->can('manage', $target)) {
            throw CannotUpdateUserAccountException::becauseUserIsNotAllowed();
        }

        DB::transaction(function () use ($admin, $target, $changes): void {
            try {
                [, $locked] = $this->lockAndAuthorizePair($admin, $target, 'manage');
            } catch (CannotModerateUserException) {
                throw CannotUpdateUserAccountException::becauseUserIsNotAllowed();
            }

            // On the locked row, not the caller's: the role that counts is the
            // one the account has now, whatever the page was drawn from.
            if ($locked->isPersona() || $changes['role'] === UserRole::Persona) {
                throw CannotUpdateUserAccountException::becauseAccountIsPersona();
            }

            $locked->forceFill([
                'name' => $changes['name'],
                'username' => $changes['username'],
                'email' => $changes['email'],
                'role' => $changes['role'],
                'trust_level' => $changes['trust_level'],
            ]);

            if ($locked->isDirty('email')) {
                $locked->email_verified_at = null;
            }

            if (filled($changes['password'] ?? null)) {
                $locked->password = Hash::make((string) $changes['password']);
            }

            $locked->save();

            $target->setRawAttributes($locked->getAttributes(), true);
        });
    }
}
