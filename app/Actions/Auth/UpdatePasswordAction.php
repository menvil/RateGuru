<?php

namespace App\Actions\Auth;

use App\Models\Concerns\LocksActorForWrite;
use App\Models\User;
use App\Support\Auth\SessionGeneration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Password changes are private/security state: living accounts (Active,
 * Limited, Banned, Shadowbanned) keep them, a Deleted tombstone is
 * terminal — a stale in-flight request must never write a usable password
 * back into an anonymized row (docs/architecture/user-lifecycle.md). A
 * Persona never signs in, so it never gains a password either.
 */
final class UpdatePasswordAction
{
    use LocksActorForWrite;

    public function execute(User $user, string $password): void
    {
        DB::transaction(function () use ($user, $password): void {
            $locked = $this->lockActor($user);

            if ($locked === null || ! $locked->canSignIn()) {
                // Generic failure: never reveal that the account was
                // deleted, used to exist or is a Persona.
                throw ValidationException::withMessages([
                    'password' => trans('auth.failed'),
                ]);
            }

            $locked->forceFill([
                'password' => Hash::make($password),
                // Every other session and remember-me cookie of the account
                // ends with the old password: a stolen session must not
                // outlive its owner changing it. The caller keeps its own
                // session current (SessionGeneration::remember()).
                'remember_token' => Str::random(60),
                'session_generation' => SessionGeneration::next(),
            ])->save();

            $user->setRawAttributes($locked->getAttributes(), true);
        });
    }
}
