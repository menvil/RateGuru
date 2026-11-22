<?php

namespace App\Enums;

/**
 * What the checks on a sign-up do with what they find: nothing at all, write
 * it to the security log and let it through, or refuse it. Anything else in
 * the setting reads as Report, so a typo can neither switch the checks off
 * nor start refusing people.
 */
enum RegistrationScreeningMode: string
{
    case Off = 'off';
    case Report = 'report';
    case Enforce = 'enforce';

    public static function current(): self
    {
        return self::tryFrom(strtolower(trim((string) config('registration.screening_mode')))) ?? self::Report;
    }
}
