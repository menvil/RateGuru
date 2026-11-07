<?php

namespace App\Actions\Auth;

use App\Models\Concerns\LocksActorForWrite;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\DB;

final class VerifyEmailAction
{
    use LocksActorForWrite;

    /**
     * @param  string  $emailHash  the hash the verification link was signed for
     */
    public function execute(User $user, string $emailHash): bool
    {
        $verified = DB::transaction(function () use ($user, $emailHash): bool {
            // The caller's instance may be stale: a verification link
            // clicked after anonymization must never restore
            // email_verified_at on a Deleted tombstone, and a Persona's
            // synthetic address is never confirmed.
            $locked = $this->lockActor($user);

            if ($locked === null || ! $locked->canSignIn()) {
                return false;
            }

            // The link proves the address it was sent to, and only that one.
            // The request authorized it against the account as it was when
            // the request started; an email change committed since then is
            // a different, unproven address, and is never confirmed here.
            if (! hash_equals(sha1($locked->getEmailForVerification()), $emailHash)) {
                return false;
            }

            if ($locked->hasVerifiedEmail() || ! $locked->markEmailAsVerified()) {
                return false;
            }

            $user->setRawAttributes($locked->getAttributes(), true);

            return true;
        });

        if (! $verified) {
            return false;
        }

        // Only after the authoritative mutation committed.
        event(new Verified($user));

        return true;
    }
}
