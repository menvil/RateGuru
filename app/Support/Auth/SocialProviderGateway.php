<?php

namespace App\Support\Auth;

use App\Data\Auth\SocialIdentity;
use App\Enums\SocialProvider;
use App\Exceptions\Auth\SocialAuthenticationException;
use App\Support\Observability\DomainLogger;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\TransferException;
use Illuminate\Contracts\Session\Session;
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
 * A provider that fails to answer — down, overloaded, unreachable — is
 * reported to Sentry and the log, so an outage that grows is seen, and the
 * person is asked to try again instead of meeting an error page. Anything
 * else (a provider suddenly answering with a different payload) is left alone
 * so it reaches the normal exception handler.
 */
final class SocialProviderGateway
{
    /** The OAuth 2.0 error a provider returns when the person declined. */
    private const string ACCESS_DENIED = 'access_denied';

    public function __construct(
        private readonly SocialiteFactory $socialite,
        private readonly SocialIdentityNormalizer $normalizer,
        private readonly SocialProviderAvailability $availability,
        private readonly DomainLogger $logger,
    ) {}

    /**
     * @throws SocialAuthenticationException when the provider is unavailable
     */
    public function redirect(SocialProvider $provider): RedirectResponse
    {
        $this->ensureAvailable($provider);

        return $this->driver($provider)->redirect();
    }

    /**
     * @param  array{code?: string|null, error?: string|null}  $callback  the validated callback query
     *
     * @throws SocialAuthenticationException for an unavailable provider, or a cancelled, refused, replayed or expired round trip
     */
    public function identityFromCallback(SocialProvider $provider, array $callback): SocialIdentity
    {
        // Also checked on the way back: a round trip started before the
        // provider was switched off must not finish after it.
        $this->ensureAvailable($provider);

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
        } catch (TransferException $failure) {
            // Reported, not swallowed: Sentry groups the same outage into one issue whose count shows it growing.
            report($failure);
            $this->logger->warning('auth.social.provider_failed', [
                'provider' => $provider->value,
                'http_status' => $failure instanceof BadResponseException ? $failure->getResponse()->getStatusCode() : null,
            ]);

            throw SocialAuthenticationException::providerFailed($provider);
        }

        return $this->normalizer->normalize($provider, $user);
    }

    /**
     * Ends a round trip without asking the provider anything, for a callback
     * that is refused before it is read: Socialite's state (and PKCE
     * verifier) for this session is discarded, so the same callback can
     * never be replayed later into a sign-in.
     */
    public function abandon(Session $session): void
    {
        $session->forget(['state', 'code_verifier']);
    }

    private function ensureAvailable(SocialProvider $provider): void
    {
        if (! $this->availability->isAvailable($provider)) {
            throw SocialAuthenticationException::providerUnavailable($provider);
        }
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
