<?php

namespace App\Support\Games\Play;

use App\Enums\GameAudience;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

/**
 * Tells who is playing, and which browser. A signed-in person is their
 * account. A browser is a random token in a first-party cookie, encrypted
 * like every cookie of the site and kept for a year; it is given one the
 * first time it opens a game or arrives through a paid link, and only the
 * token's SHA-256 is ever stored. The games use it for anonymous visitors;
 * the traffic tracker knows every visit by it, signed in or not.
 */
final class GameVisitorResolver
{
    private bool $issued = false;

    public function __construct(private readonly Request $request) {}

    public function resolve(): GameVisitor
    {
        $user = $this->request->user();

        if ($user instanceof User) {
            return new GameVisitor(GameAudience::Authenticated, (int) $user->id, null, $this->browserHash());
        }

        $hash = $this->ensureBrowserHash();

        return new GameVisitor(GameAudience::Anonymous, null, $hash, $hash);
    }

    /** The browser's hash when it already holds a token (or was just given one), without giving it one. */
    public function browserHash(): ?string
    {
        $token = $this->existingToken();

        return $token === null ? null : hash('sha256', $token);
    }

    /** The browser's hash, giving it a token first when it has none. */
    public function ensureBrowserHash(): string
    {
        $token = $this->existingToken();

        if ($token === null) {
            $token = Str::random(40);
            $this->issued = true;
            Cookie::queue(Cookie::make((string) config('games.visitor_cookie.name'), $token, (int) config('games.visitor_cookie.lifetime_minutes'), httpOnly: true, sameSite: 'lax'));
        }

        return hash('sha256', $token);
    }

    /** Whether this request gave the browser its token: a browser seen for the first time. */
    public function issuedNow(): bool
    {
        return $this->issued;
    }

    private function existingToken(): ?string
    {
        $name = (string) config('games.visitor_cookie.name');
        $token = $this->request->cookie($name);

        if (is_string($token) && preg_match('/\A[A-Za-z0-9]{40}\z/', $token) === 1) {
            return $token;
        }

        $queued = Cookie::queued($name);

        if ($queued !== null && preg_match('/\A[A-Za-z0-9]{40}\z/', (string) $queued->getValue()) === 1) {
            return (string) $queued->getValue();
        }

        return null;
    }
}
