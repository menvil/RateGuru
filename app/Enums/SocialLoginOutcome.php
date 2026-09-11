<?php

namespace App\Enums;

/**
 * What a social sign-in callback ended up doing, so the controller can pick
 * the transport response without re-deriving any of the account logic.
 */
enum SocialLoginOutcome
{
    /** An account already linked to this identity was signed in. */
    case LoggedIn;

    /** A new account was created for this identity and signed in. */
    case Registered;

    /** The identity was attached to the account that was already signed in. */
    case Linked;

    /**
     * The identity's email belongs to an existing account nobody is signed in
     * to: a pending link was stored and the person must sign in to claim it.
     */
    case PendingLink;

    /** Whether this callback authenticated a session that was not authenticated before. */
    public function authenticatedSession(): bool
    {
        return $this === self::LoggedIn || $this === self::Registered;
    }
}
