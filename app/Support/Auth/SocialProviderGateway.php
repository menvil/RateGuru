<?php

namespace App\Support\Auth;

use App\Data\Auth\SocialIdentity;
use App\Enums\SocialProvider;
use App\Exceptions\Auth\SocialAuthenticationException;
use Laravel\Socialite\Contracts\Factory as SocialiteFactory;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\FacebookProvider;
use Laravel\Socialite\Two\InvalidStateException;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * The only place the application talks to Socialite.
 *
 * Keeps the provider setup in one spot — minimal scopes and fields, and OAuth
 * state bound to the session, never stateless — and translates the failures a
 * real round trip is expected to produce into SocialAuthenticationException.
 * Everything else (a network error, a provider suddenly answering with a
 * different payload) is left alone so it reaches the normal exception handler.
 */
final class SocialProviderGateway
{
    /** The OAuth 2.0 error a provider returns when the person declined. */
    private const string ACCESS_DENIED = 'access_denied';

    public function __construct(
        private readonly SocialiteFactory $socialite,
        private readonly SocialIdentityNormalizer $normalizer,
    ) {}

    public function redirect(SocialProvider $provider): RedirectResponse
    {
        return $this->driver($provider)->redirect();
    }

    /**
     * @param  array{code?: string|null, error?: string|null}  $callback  the validated callback query
     *
     * @throws SocialAuthenticationException for a cancelled, refused, replayed or expired round trip
     */
    public function identityFromCallback(SocialProvider $provider, array $callback): SocialIdentity
    {
        $error = $callback['error'] ?? null;

        if (is_string($error) && $error !== '') {
            throw $error === self::ACCESS_DENIED
                ? SocialAuthenticationException::cancelled($provider)
                : SocialAuthenticationException::providerRejected($provider);
        }

        $code = $callback['code'] ?? null;

        if (! is_string($code) || $code === '') {
            // Without a code there is nothing to exchange; asking Socialite
            // would only turn a provider refusal into a token-endpoint error.
            throw SocialAuthenticationException::providerRejected($provider);
        }

        try {
            $user = $this->driver($provider)->user();
        } catch (InvalidStateException) {
            throw SocialAuthenticationException::invalidState($provider);
        }

        return $this->normalizer->normalize($provider, $user);
    }

    private function driver(SocialProvider $provider): Provider
    {
        $driver = $this->socialite->driver($provider->value);

        // Socialite's defaults already match, but the minimum is a contract of
        // this integration, not something a package upgrade may widen.
        if ($driver instanceof AbstractProvider) {
            $driver->setScopes($provider->scopes());
        }

        if ($driver instanceof FacebookProvider) {
            // Graph fields are separate from scopes: left alone, Facebook also
            // returns gender, verified, link and a picture, none of which the
            // application reads.
            $driver->fields(['name', 'email']);
        }

        return $driver;
    }
}
