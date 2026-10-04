<?php

namespace App\Support\Notifications;

use App\Models\User;

final class MailAddressee
{
    /**
     * What to call the recipient of an email. display_name is what they chose
     * to be called and name is what they registered as; either can be blank,
     * and a greeting reading "Hello, !" is worse than a generic one.
     */
    public static function nameOf(object $notifiable): string
    {
        if (! $notifiable instanceof User) {
            return (string) config('app.name');
        }

        foreach ([$notifiable->display_name, $notifiable->name] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return $candidate;
            }
        }

        return (string) config('app.name');
    }
}
