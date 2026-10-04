<?php

namespace App\Enums;

/**
 * The two states of the one authentication modal. Not tabs and not two
 * modals: the same dialog shows either the login or the registration form.
 */
enum AuthModalMode: string
{
    case Login = 'login';
    case Register = 'register';

    /** Anything that is not exactly a known mode is the login form. */
    public static function fromInput(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom($value) ?? self::Login) : self::Login;
    }
}
