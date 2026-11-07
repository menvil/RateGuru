<?php

namespace App\Enums;

/**
 * The states of the one authentication modal. Not tabs and not several
 * modals: the same dialog shows the login form, the registration form or
 * the form that emails a password reset link.
 */
enum AuthModalMode: string
{
    case Login = 'login';
    case Register = 'register';
    case ForgotPassword = 'forgot-password';

    /** Anything that is not exactly a known mode is the login form. */
    public static function fromInput(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom($value) ?? self::Login) : self::Login;
    }
}
