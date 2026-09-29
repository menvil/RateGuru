<?php

namespace App\Support\Auth;

use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Str;

/**
 * Which "generation" of an account's sessions a browser belongs to.
 *
 * Every sign-in records the account's current generation in its session.
 * Starting a new generation — when control of an account has just been
 * proven by someone who may not be the person who was signed in before —
 * ends every older session on its next request. It works for every session
 * driver, and a session that predates this mechanism counts as older.
 */
final class SessionGeneration
{
    public const string SESSION_KEY = 'auth.session_generation';

    public static function remember(Session $session, User $user): void
    {
        $session->put(self::SESSION_KEY, $user->session_generation);
    }

    public static function isCurrent(Session $session, User $user): bool
    {
        // An account that never started a new generation has nothing to end.
        if ($user->session_generation === null) {
            return true;
        }

        return $session->get(self::SESSION_KEY) === $user->session_generation;
    }

    /** A fresh value for users.session_generation. */
    public static function next(): string
    {
        return Str::random(40);
    }
}
