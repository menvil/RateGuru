<?php

namespace App\Actions\Auth;

use App\Models\User;

final class SendEmailVerificationNotificationAction
{
    public function execute(User $user): bool
    {
        // A Persona's synthetic address has nothing to confirm and nowhere
        // to send a link to.
        if ($user->hasVerifiedEmail() || $user->isPersona()) {
            return false;
        }

        $user->sendEmailVerificationNotification();

        return true;
    }
}
