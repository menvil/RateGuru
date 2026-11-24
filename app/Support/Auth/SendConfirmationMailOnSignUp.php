<?php

namespace App\Support\Auth;

use App\Actions\Auth\SendEmailVerificationNotificationAction;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;

/**
 * Takes the place of the framework's listener, bound to it in the container.
 * The account exists by the time this runs, so a mail server that is down
 * must not turn the sign-up into an error page or keep the other listeners
 * of Registered from running. The mail also counts towards the account's
 * limit, so the button beside it cannot send a second one at once.
 */
final class SendConfirmationMailOnSignUp extends SendEmailVerificationNotification
{
    public function __construct(private readonly SendEmailVerificationNotificationAction $send) {}

    public function handle(Registered $event): void
    {
        if ($event->user instanceof User) {
            $this->send->attempt($event->user);
        }
    }
}
