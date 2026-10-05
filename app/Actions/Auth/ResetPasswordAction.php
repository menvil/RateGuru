<?php

namespace App\Actions\Auth;

use App\Models\Concerns\LocksActorForWrite;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ResetPasswordAction
{
    use LocksActorForWrite;

    /** @param array{token: string, email: string, password: string} $validated */
    public function execute(array $validated): string
    {
        $terminalRejected = false;

        $status = Password::reset(
            $validated,
            function (User $user) use ($validated, &$terminalRejected): void {
                // The broker-provided instance may be stale: anonymization
                // can commit between token validation and this write. Lock
                // the row by immutable primary key and require a living
                // account — a tombstone gets no password, no fresh
                // remember_token and no PasswordReset event.
                $written = DB::transaction(function () use ($user, $validated): ?User {
                    $locked = $this->lockActor($user);

                    if ($locked === null || ! $locked->canAuthenticate()) {
                        return null;
                    }

                    $locked->forceFill([
                        'password' => Hash::make($validated['password']),
                        'remember_token' => Str::random(60),
                    ])->save();

                    return $locked;
                });

                if ($written === null) {
                    $terminalRejected = true;

                    return;
                }

                // The written row, not the broker's instance: $user still holds
                // the pre-reset password hash and remember_token, and a listener
                // that reads either of them off this event would be reading the
                // credentials the reset just replaced.
                event(new PasswordReset($written));
            },
        );

        if ($terminalRejected) {
            // The existing generic invalid-user outcome: never reveal that
            // the account was deleted or used to exist.
            throw ValidationException::withMessages([
                'email' => __(Password::INVALID_USER),
            ]);
        }

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => __($status),
            ]);
        }

        return $status;
    }
}
