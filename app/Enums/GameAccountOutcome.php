<?php

namespace App\Enums;

/**
 * What a guest's attempt led to: a new account, or a sign-in to one that
 * already existed. An email left on the way is neither.
 */
enum GameAccountOutcome: string
{
    case Registered = 'registered';
    case SignedIn = 'signed_in';
}
