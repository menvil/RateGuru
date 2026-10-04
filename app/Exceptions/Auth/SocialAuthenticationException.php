<?php

namespace App\Exceptions\Auth;

use App\Enums\SocialProvider;
use RuntimeException;

/**
 * An expected, user-facing failure of the social sign-in flow.
 *
 * Every case maps to a generic translated message: no lifecycle status, no
 * internal id, no provider payload ever reaches the login page. Anything that
 * is not one of these named cases is deliberately NOT wrapped, so programming
 * and infrastructure errors keep reaching the normal exception handler and
 * Sentry instead of turning into a quiet "could not sign you in".
 */
class SocialAuthenticationException extends RuntimeException
{
    private function __construct(
        string $message,
        private readonly string $translationKey,
        private readonly SocialProvider $provider,
    ) {
        parent::__construct($message);
    }

    /**
     * Sign-in with this provider is switched off in Project settings, or its
     * keys are not configured: nothing is sent to the provider and nothing
     * it sends back is accepted.
     */
    public static function providerUnavailable(SocialProvider $provider): self
    {
        return new self("Sign-in with {$provider->label()} is unavailable.", 'auth.social.unavailable', $provider);
    }

    /** The person declined at the provider's consent screen. */
    public static function cancelled(SocialProvider $provider): self
    {
        return new self("Sign-in with {$provider->label()} was cancelled.", 'auth.social.cancelled', $provider);
    }

    /** The provider answered the callback with an error, or without a code. */
    public static function providerRejected(SocialProvider $provider): self
    {
        return new self("{$provider->label()} did not complete the sign-in.", 'auth.social.failed', $provider);
    }

    /** The OAuth state did not match the session: expired, replayed or forged. */
    public static function invalidState(SocialProvider $provider): self
    {
        return new self("The {$provider->label()} sign-in state is invalid or expired.", 'auth.social.expired', $provider);
    }

    /** The provider profile carries no email address to identify a person by. */
    public static function emailMissing(SocialProvider $provider): self
    {
        return new self("The {$provider->label()} account did not share an email address.", 'auth.social.email_missing', $provider);
    }

    /**
     * The account behind the identity can no longer authenticate. Reported
     * with the same generic failure as a password login — never as a
     * lifecycle status.
     */
    public static function accountUnavailable(SocialProvider $provider): self
    {
        return new self("The account behind the {$provider->label()} identity cannot authenticate.", 'auth.failed', $provider);
    }

    /**
     * The identity's email is the address of a different account: connecting
     * it here would leave that address signing in to two accounts.
     */
    public static function emailBelongsToAnotherAccount(SocialProvider $provider): self
    {
        return new self("The {$provider->label()} account email belongs to another account.", 'auth.social.email_taken', $provider);
    }

    /** The identity already belongs to a different account and is never reassigned. */
    public static function identityAlreadyLinked(SocialProvider $provider): self
    {
        return new self("The {$provider->label()} identity is already linked to another account.", 'auth.social.already_linked', $provider);
    }

    /** The account already holds a different identity of this provider, which is never replaced. */
    public static function providerAlreadyLinked(SocialProvider $provider): self
    {
        return new self("The account already has a different {$provider->label()} identity linked.", 'auth.social.provider_already_linked', $provider);
    }

    /** The translated, generic message that is safe to show on the login page. */
    public function userMessage(): string
    {
        return trans($this->translationKey, ['provider' => $this->provider->label()]);
    }
}
