<?php

namespace App\Support\Auth;

use App\Enums\SocialCallbackIntent;
use App\Enums\SocialProvider;
use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Carbon;

/**
 * The server's record that the signed-in account asked to connect a provider.
 *
 * OAuth state proves that a callback belongs to the round trip this session
 * started; it says nothing about whether that round trip was a sign-in or a
 * connection, or which account the connection was for. This is that second
 * half, and the only thing that lets a callback attach an identity to a
 * signed-in account: written by the Connected accounts card right before the
 * provider redirect, bound to one account and one provider, valid for ten
 * minutes, and consumed by the first callback whatever its outcome.
 *
 * Server-side session only, and deliberately minimal: no token, no email.
 * Separate from AuthSurfaceContext, which only decides where a sign-in
 * started from and returns to.
 */
final readonly class SocialLinkContext
{
    public const string SESSION_KEY = 'auth.social_link_context';

    public const int LIFETIME_MINUTES = 10;

    private function __construct(
        private int $userId,
        private SocialProvider $provider,
        private Carbon $createdAt,
    ) {}

    public static function start(User $user, SocialProvider $provider, Carbon $now): self
    {
        return new self((int) $user->getKey(), $provider, $now);
    }

    /** One slot: a newer connection replaces an abandoned one, like the OAuth state it travels with. */
    public function remember(Session $session): void
    {
        $session->put(self::SESSION_KEY, [
            'user_id' => $this->userId,
            'provider' => $this->provider->value,
            'created_at' => $this->createdAt->getTimestamp(),
        ]);
    }

    /** An ordinary sign-in round trip starts: whatever connection was abandoned before is void. */
    public static function forget(Session $session): void
    {
        $session->forget(self::SESSION_KEY);
    }

    /**
     * Consumes the context — one-time, whatever the outcome — and decides
     * what this callback may do. A context that is present but cannot be
     * read back never authorizes anything.
     */
    public static function consume(Session $session, SocialProvider $provider, ?User $user, Carbon $now): SocialCallbackIntent
    {
        if (! $session->has(self::SESSION_KEY)) {
            return SocialCallbackIntent::SignIn;
        }

        $context = self::fromSession($session->pull(self::SESSION_KEY));

        return $context !== null && $context->authorizes($provider, $user, $now)
            ? SocialCallbackIntent::Connect
            : SocialCallbackIntent::StaleConnect;
    }

    private function authorizes(SocialProvider $provider, ?User $user, Carbon $now): bool
    {
        return $user !== null
            && (int) $user->getKey() === $this->userId
            && $provider === $this->provider
            && ! $this->createdAt->copy()->addMinutes(self::LIFETIME_MINUTES)->lessThan($now);
    }

    /** Null for anything that is not a well-formed payload written by remember(). */
    private static function fromSession(mixed $payload): ?self
    {
        if (! is_array($payload)) {
            return null;
        }

        $userId = $payload['user_id'] ?? null;
        $provider = is_string($payload['provider'] ?? null) ? SocialProvider::tryFrom($payload['provider']) : null;
        $createdAt = $payload['created_at'] ?? null;

        if (! is_int($userId) || $userId < 1 || $provider === null || ! is_int($createdAt)) {
            return null;
        }

        return new self($userId, $provider, Carbon::createFromTimestamp($createdAt));
    }
}
