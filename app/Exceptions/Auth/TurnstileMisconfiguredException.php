<?php

namespace App\Exceptions\Auth;

use RuntimeException;

/** Reported, never thrown at a visitor: Cloudflare refused the site's own secret key. */
final class TurnstileMisconfiguredException extends RuntimeException
{
    public static function secretRefused(): self
    {
        return new self('Cloudflare refused the Turnstile secret key.');
    }
}
