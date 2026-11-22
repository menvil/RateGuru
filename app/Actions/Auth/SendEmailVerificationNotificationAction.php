<?php

namespace App\Actions\Auth;

use App\Exceptions\Abuse\RateLimitExceededException;
use App\Models\User;
use App\Support\AbuseGuards\ActionRateLimiter;
use App\Support\AbuseGuards\RateLimitKey;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

final class SendEmailVerificationNotificationAction
{
    public function __construct(private readonly ActionRateLimiter $rateLimiter) {}

    /**
     * Counted per account, whatever asked for the link: each one is a mail
     * to an address nobody has proved is this account's.
     *
     * @return bool whether a link was sent
     *
     * @throws RateLimitExceededException when the account has asked too often
     * @throws TransportExceptionInterface when the mail server refuses the message
     */
    public function execute(User $user): bool
    {
        // A Persona's synthetic address has nothing to confirm and nowhere
        // to send a link to.
        if ($user->hasVerifiedEmail() || $user->isPersona()) {
            return false;
        }

        $this->rateLimiter->hitOrFail(
            RateLimitKey::userAction('email-confirmation-minute', $user),
            (int) config('rate_limits.email_confirmation.per_minute'),
            60,
        );
        $this->rateLimiter->hitOrFail(
            RateLimitKey::userAction('email-confirmation-hour', $user),
            (int) config('rate_limits.email_confirmation.per_hour'),
            3600,
        );

        $user->sendEmailVerificationNotification();

        return true;
    }

    /**
     * A link nobody clicked a button for, on signing up or changing address.
     * What the person was doing has already happened, so a limit reached or
     * a mail server down never fails it: every page offers a new link.
     */
    public function attempt(User $user): void
    {
        try {
            $this->execute($user);
        } catch (RateLimitExceededException) {
            // The links already sent are still valid.
        } catch (TransportExceptionInterface $exception) {
            report($exception);
        }
    }
}
