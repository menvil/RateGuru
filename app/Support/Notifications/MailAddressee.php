<?php

namespace App\Support\Notifications;

use App\Models\User;
use App\Support\Settings\ProjectSettingsManager;

final class MailAddressee
{
    /**
     * What to call the recipient of an email. display_name is what they chose
     * to be called and name is what they registered as; either can be blank,
     * and a greeting reading "Hello, !" is worse than a generic one — the
     * site's own name, as the project's settings give it.
     */
    public static function nameOf(object $notifiable): string
    {
        if (! $notifiable instanceof User) {
            return app(ProjectSettingsManager::class)->current()->siteName();
        }

        foreach ([$notifiable->display_name, $notifiable->name] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return $candidate;
            }
        }

        return app(ProjectSettingsManager::class)->current()->siteName();
    }

    /**
     * How an email opens. Whoever signs up can type anyone's address, so
     * what they call themselves never reaches a mailbox not yet confirmed.
     */
    public static function greetingFor(object $notifiable): string
    {
        return self::isUnconfirmed($notifiable)
            ? __('mail.fallback_greeting')
            : __('mail.greeting', ['name' => self::nameOf($notifiable)]);
    }

    /** Whether this recipient is an account whose address nobody has proved is theirs. */
    public static function isUnconfirmed(object $notifiable): bool
    {
        return $notifiable instanceof User && ! $notifiable->hasVerifiedEmail();
    }
}
