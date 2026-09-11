<?php

namespace App\Support\Auth;

use App\Data\Auth\SocialIdentity;
use App\Enums\SocialProvider;
use Illuminate\Support\Str;
use Laravel\Socialite\AbstractUser;
use Laravel\Socialite\Contracts\User as ProviderUser;
use UnexpectedValueException;

/**
 * Turns whatever Socialite hands back into the one shape the application
 * reasons about, applying the two rules that must never drift between
 * providers: the email is normalized exactly like a typed registration
 * (trim + lowercase), and "verified" is only granted where the provider is
 * genuinely the authority for the mailbox.
 */
final class SocialIdentityNormalizer
{
    public function normalize(SocialProvider $provider, ProviderUser $user): SocialIdentity
    {
        $providerUserId = trim((string) $user->getId());

        if ($providerUserId === '') {
            // Not a user mistake and not something a retry fixes: a provider
            // profile without a subject is an integration failure that has to
            // surface, not a login that quietly fails.
            throw new UnexpectedValueException(sprintf('%s returned a profile without a user id.', $provider->label()));
        }

        $email = $this->normalizeEmail($user->getEmail());

        return new SocialIdentity(
            provider: $provider,
            providerUserId: $providerUserId,
            email: $email,
            name: $this->blankToNull($user->getName()),
            nickname: $this->blankToNull($user->getNickname()),
            emailVerifiedByProvider: $email !== null
                && $this->providerVouchesForEmail($provider, $email, $this->rawProfile($user)),
        );
    }

    /** The same normalization a typed registration email goes through. */
    public function normalizeEmail(?string $email): ?string
    {
        $normalized = Str::lower(trim((string) $email));

        return $normalized === '' ? null : $normalized;
    }

    /** @param  array<string, mixed>  $raw */
    private function providerVouchesForEmail(SocialProvider $provider, string $email, array $raw): bool
    {
        return match ($provider) {
            // Google hosts the mailbox for gmail.com itself, and for a
            // Workspace domain it reports the domain (`hd`) next to an explicit
            // email_verified claim. A Google account registered on any other
            // third-party address is NOT the authority for that mailbox, so it
            // goes through RateGuru's own verification like everyone else —
            // and so does anything whose claims are missing or malformed.
            SocialProvider::Google => str_ends_with($email, '@gmail.com')
                || (($raw['email_verified'] ?? null) === true
                    && is_string($raw['hd'] ?? null)
                    && trim($raw['hd']) !== ''),
            // Facebook never hosts the mailbox: always RateGuru verification.
            SocialProvider::Facebook => false,
        };
    }

    /** @return array<string, mixed> */
    private function rawProfile(ProviderUser $user): array
    {
        return $user instanceof AbstractUser ? (array) $user->getRaw() : [];
    }

    private function blankToNull(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
