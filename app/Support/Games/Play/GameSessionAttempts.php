<?php

namespace App\Support\Games\Play;

use Illuminate\Contracts\Session\Session;

/**
 * The attempts a browser's session has started, newest last, by their public
 * ids — the proof that a guest's attempt is theirs, kept across a sign-in
 * because the session keeps its data then. At most a fixed number are kept.
 */
final class GameSessionAttempts
{
    private const KEY = 'games.attempts';

    /** @return list<string> */
    public static function all(Session $session): array
    {
        $ids = $session->get(self::KEY, []);

        return is_array($ids) ? array_values(array_filter($ids, 'is_string')) : [];
    }

    public static function remember(Session $session, string $publicId): void
    {
        $ids = array_values(array_diff(self::all($session), [$publicId]));
        $ids[] = $publicId;
        $session->put(self::KEY, array_slice($ids, -max(1, (int) config('games.session_attempts', 20))));
    }

    public static function holds(Session $session, string $publicId): bool
    {
        return in_array($publicId, self::all($session), true);
    }
}
