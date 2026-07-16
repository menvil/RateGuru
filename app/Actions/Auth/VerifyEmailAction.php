<?php

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Verified;

final class VerifyEmailAction
{
    public function execute(User $user): bool
    {
        if ($user->hasVerifiedEmail() || ! $user->markEmailAsVerified()) {
            return false;
        }

        event(new Verified($user));

        return true;
    }
}
