<?php

namespace App\Actions\Auth;

use App\Models\User;

final class SendEmailVerificationNotificationAction
{
    public function execute(User $user): bool
    {
        if ($user->hasVerifiedEmail()) {
            return false;
        }

        $user->sendEmailVerificationNotification();

        return true;
    }
}
