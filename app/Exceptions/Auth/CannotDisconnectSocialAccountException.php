<?php

namespace App\Exceptions\Auth;

use App\Enums\SocialProvider;
use RuntimeException;

/**
 * An expected refusal to disconnect a provider from an account, with a
 * message that is safe to show on the profile page.
 */
class CannotDisconnectSocialAccountException extends RuntimeException
{
    private function __construct(
        string $message,
        private readonly string $translationKey,
        private readonly SocialProvider $provider,
    ) {
        parent::__construct($message);
    }

    public static function notConnected(SocialProvider $provider): self
    {
        return new self("{$provider->label()} is not connected to this account.", 'profile.connected.not_connected_error', $provider);
    }

    /** Disconnecting it would leave the account with no way to sign in. */
    public static function lastSignInMethod(SocialProvider $provider): self
    {
        return new self("{$provider->label()} is the account's only way to sign in.", 'profile.connected.last_method_error', $provider);
    }

    public static function accountUnavailable(SocialProvider $provider): self
    {
        return new self('The account can no longer be changed.', 'auth.failed', $provider);
    }

    public function userMessage(): string
    {
        return trans($this->translationKey, ['provider' => $this->provider->label()]);
    }
}
