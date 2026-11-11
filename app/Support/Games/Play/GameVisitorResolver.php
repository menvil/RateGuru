<?php

namespace App\Support\Games\Play;

use App\Enums\GameAudience;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

/**
 * Tells who is playing. A signed-in person is their account. An anonymous
 * browser is a random token in a first-party cookie, encrypted like every
 * cookie of the site, kept for a year and used by games alone; it is given
 * one the first time it opens a game, and only the token's SHA-256 is ever
 * stored.
 */
final class GameVisitorResolver
{
    public function __construct(private readonly Request $request) {}

    public function resolve(): GameVisitor
    {
        $user = $this->request->user();

        if ($user instanceof User) {
            return new GameVisitor(GameAudience::Authenticated, (int) $user->id, null);
        }

        return new GameVisitor(GameAudience::Anonymous, null, hash('sha256', $this->token()));
    }

    /** The anonymous browser's token, made and set the first time it is asked for. */
    private function token(): string
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

        $token = Str::random(40);
        Cookie::queue(Cookie::make($name, $token, (int) config('games.visitor_cookie.lifetime_minutes'), httpOnly: true, sameSite: 'lax'));

        return $token;
    }
}
