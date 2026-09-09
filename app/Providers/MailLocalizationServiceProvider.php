<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\ServiceProvider;

/**
 * The two emails Laravel sends on our behalf, written in our words.
 *
 * Out of the box these render the framework's own English sentences, and the
 * only way to translate them is to put those sentences into lang/{locale}.json
 * as KEYS — "Please click the button below to verify your email address." and
 * so on. That is fragile in a way that bites quietly: the key is prose owned by
 * a dependency, so a Laravel upgrade that rewords a line silently drops the
 * translation and the email reverts to English with nothing failing.
 *
 * So both are built here instead, from lang/{locale}/mail.php with keys we own.
 * The upgrade risk goes away, the subject line becomes ours rather than
 * "Verify Email Address", and every string a recipient can read lives in one
 * file per language — which is what makes the translation-parity guard able to
 * prove a new language is actually complete.
 *
 * The locale is not chosen here. NotificationSender has already wrapped this
 * render in withLocale() for the recipient's preference, so __() resolves in
 * their language; see User::preferredLocale().
 */
final class MailLocalizationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        VerifyEmail::toMailUsing(fn (object $notifiable, string $url): MailMessage => (new MailMessage)
            ->subject(__('mail.verify.subject'))
            ->greeting(__('mail.greeting', ['name' => self::addressee($notifiable)]))
            ->line(__('mail.verify.line'))
            ->action(__('mail.verify.action'), $url)
            ->line(__('mail.verify.ignore'))
            ->salutation(__('mail.salutation', ['app' => config('app.name')])));

        ResetPassword::toMailUsing(fn (object $notifiable, string $token): MailMessage => (new MailMessage)
            ->subject(__('mail.reset.subject'))
            ->greeting(__('mail.greeting', ['name' => self::addressee($notifiable)]))
            ->line(__('mail.reset.line'))
            ->action(__('mail.reset.action'), self::resetUrl($token, $notifiable))
            ->line(__('mail.reset.expire', [
                'count' => config('auth.passwords.'.config('auth.defaults.passwords').'.expire'),
            ]))
            ->line(__('mail.reset.ignore'))
            ->salutation(__('mail.salutation', ['app' => config('app.name')])));
    }

    /**
     * What to call the recipient. display_name is what they chose to be called
     * and name is what they registered as; either can be blank, and a greeting
     * reading "Hello, !" is worse than a generic one.
     */
    private static function addressee(object $notifiable): string
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

    /**
     * Byte-for-byte the URL Laravel's own notification builds — route(..., false)
     * wrapped in url() — so replacing the template changes the wording and
     * nothing about where the link goes.
     */
    private static function resetUrl(string $token, object $notifiable): string
    {
        return url(route('password.reset', [
            'token' => $token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ], false));
    }
}
