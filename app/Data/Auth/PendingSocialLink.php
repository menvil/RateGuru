<?php

namespace App\Data\Auth;

use App\Enums\SocialProvider;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * A social identity waiting for its owner to prove they hold the RateGuru
 * account that already uses the identity's email address.
 *
 * Lives only in the server-side session, carries no token, and is consumed
 * exactly once: CompletePendingSocialLinkAction pulls it whatever the
 * outcome, so a stale or hostile payload can never be replayed.
 */
final readonly class PendingSocialLink
{
    public const string SESSION_KEY = 'auth.pending_social_link';

    public const int LIFETIME_MINUTES = 10;

    public function __construct(
        public SocialProvider $provider,
        public string $providerUserId,
        public string $email,
        public Carbon $createdAt,
    ) {}

    public static function fromIdentity(SocialIdentity $identity, Carbon $now): self
    {
        if ($identity->email === null) {
            throw new InvalidArgumentException('A pending social link needs the provider email to match against.');
        }

        return new self($identity->provider, $identity->providerUserId, $identity->email, $now);
    }

    /**
     * Null for anything that is not a well-formed payload written by
     * toSession(): the session is trusted storage, but not trusted structure.
     */
    public static function fromSession(mixed $payload): ?self
    {
        if (! is_array($payload)) {
            return null;
        }

        $provider = is_string($payload['provider'] ?? null) ? SocialProvider::tryFrom($payload['provider']) : null;
        $providerUserId = $payload['provider_user_id'] ?? null;
        $email = $payload['email'] ?? null;
        $createdAt = $payload['created_at'] ?? null;

        if ($provider === null
            || ! is_string($providerUserId) || $providerUserId === ''
            || ! is_string($email) || $email === ''
            || ! is_int($createdAt)
        ) {
            return null;
        }

        return new self($provider, $providerUserId, $email, Carbon::createFromTimestamp($createdAt));
    }

    /** @return array{provider: string, provider_user_id: string, email: string, created_at: int} */
    public function toSession(): array
    {
        return [
            'provider' => $this->provider->value,
            'provider_user_id' => $this->providerUserId,
            'email' => $this->email,
            'created_at' => $this->createdAt->getTimestamp(),
        ];
    }

    public function isExpired(Carbon $now): bool
    {
        return $this->createdAt->copy()->addMinutes(self::LIFETIME_MINUTES)->lessThan($now);
    }

    /**
     * The identity to link once ownership is proven. Verification metadata
     * is deliberately absent: linking never touches email_verified_at.
     */
    public function toIdentity(): SocialIdentity
    {
        return new SocialIdentity(
            provider: $this->provider,
            providerUserId: $this->providerUserId,
            email: $this->email,
            name: null,
            nickname: null,
            emailVerifiedByProvider: false,
        );
    }
}
