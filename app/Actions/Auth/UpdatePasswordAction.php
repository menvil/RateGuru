<?php

namespace App\Actions\Auth;

use App\Models\Concerns\LocksActorForWrite;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Password changes are private/security state: living accounts (Active,
 * Limited, Banned, Shadowbanned) keep them, a Deleted tombstone is
 * terminal — a stale in-flight request must never write a usable password
 * back into an anonymized row (docs/architecture/user-lifecycle.md).
 */
final class UpdatePasswordAction
{
    use LocksActorForWrite;

    public function execute(User $user, string $password): void
    {
        DB::transaction(function () use ($user, $password): void {
            $locked = $this->lockActor($user);

            if ($locked === null || ! $locked->canAuthenticate()) {
                // Generic failure: never reveal that the account was
                // deleted or used to exist.
                throw ValidationException::withMessages([
                    'password' => trans('auth.failed'),
                ]);
            }

            $locked->forceFill([
                'password' => Hash::make($password),
            ])->save();

            $user->setRawAttributes($locked->getAttributes(), true);
        });
    }
}
