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
}
