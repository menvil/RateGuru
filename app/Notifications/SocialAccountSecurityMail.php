<?php

namespace App\Notifications;

use App\Enums\SocialProvider;
use App\Support\Notifications\MailAddressee;
use App\Support\Settings\ProjectSettingsManager;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * The one layout both connected-account security emails share, rendered
 * from lang/{locale}/mail.php. NotificationSender has already switched to
 * the recipient's language; see User::preferredLocale().
 */
final class SocialAccountSecurityMail
{
    /** @param  string  $keys  the mail.php section holding subject, line and not_you */
    public static function build(string $keys, object $notifiable, SocialProvider $provider, ?string $providerEmail): MailMessage
    {
        $siteName = app(ProjectSettingsManager::class)->current()->siteName();
        $replace = ['provider' => $provider->label(), 'site' => $siteName];

        $message = (new MailMessage)
            ->subject(__("{$keys}.subject", $replace))
            ->greeting(MailAddressee::greetingFor($notifiable))
            ->line(__("{$keys}.line", $replace));

        // Whoever registered an address that is not theirs can connect their
        // own provider account, so its address is not shown to that mailbox.
        if ($providerEmail !== null && $providerEmail !== '' && ! MailAddressee::isUnconfirmed($notifiable)) {
            $message->line(__('mail.social.account', ['provider' => $provider->label(), 'email' => $providerEmail]));
        }

        return $message
            ->line(__("{$keys}.not_you"))
            ->action(__('mail.social.action'), route('profile.edit').'#connected-accounts')
            ->salutation(__('mail.salutation', ['app' => $siteName]));
    }
}
