<?php

namespace App\Actions\Auth;

use App\Models\Concerns\LocksActorForWrite;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\DB;

final class VerifyEmailAction
{
    use LocksActorForWrite;

    public function execute(User $user): bool
    {
        $verified = DB::transaction(function () use ($user): bool {
            // The caller's instance may be stale: a verification link
            // clicked after anonymization must never restore
            // email_verified_at on a Deleted tombstone.
            $locked = $this->lockActor($user);

            if ($locked === null || ! $locked->canAuthenticate()) {
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
