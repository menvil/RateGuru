<?php

namespace App\Enums;

/**
 * What a provider callback is allowed to do, decided from what the server
 * itself recorded when the round trip started — never from whether the
 * session happens to be signed in by the time the callback arrives.
 */
enum SocialCallbackIntent
{
    /** No connection was started: an ordinary sign-in or registration. */
    case SignIn;

    /** The signed-in account that started connecting this provider is back to finish it. */
    case Connect;

    /**
     * A connection was started, but this callback cannot finish it: the
     * context expired, names another provider, or the session is no longer
     * signed in to the account that started it.
     */
    case StaleConnect;
}
