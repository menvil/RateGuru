<?php

namespace App\Notifications;

use App\Enums\SocialProvider;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Security notice: a Google or Facebook sign-in was added to the account —
 * from the profile, by signing in with a confirmed email, or by completing
 * a pending link. Sent to the account's own address so an addition nobody
 * asked for does not go unnoticed.
 */
final class SocialAccountConnectedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly SocialProvider $provider,
        public readonly ?string $providerEmail,
    ) {
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Queued, so the account may have been deleted by the time the job
     * runs; a tombstone never receives email.
     */
    public function shouldSend(object $notifiable, string $channel): bool
    {
        return ! ($notifiable instanceof User && $notifiable->isTombstoned());
    }

    public function toMail(object $notifiable): MailMessage
    {
        return SocialAccountSecurityMail::build(
            'mail.social.connected',
            $notifiable,
            $this->provider,
            $this->providerEmail,
        );
    }
}
