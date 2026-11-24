<?php

namespace App\Enums;

/**
 * What Cloudflare said about a sign-up's challenge. Unavailable is kept apart
 * from Rejected because it is no fault of the person signing up.
 */
enum TurnstileOutcome
{
    case Passed;
    case Rejected;
    case Unavailable;
}
