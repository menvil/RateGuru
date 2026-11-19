<?php

namespace App\Enums;

/** How an account signed up. */
enum AccountSignupMethod: string
{
    case Email = 'email';
    case Google = 'google';
    case Facebook = 'facebook';

    public function label(): string
    {
        return match ($this) {
            self::Email => 'Email',
            self::Google => 'Google',
            self::Facebook => 'Facebook',
        };
    }
}
