<?php

namespace App\Enums;

/**
 * The external identity providers RateGuru accepts for social sign-in.
 *
 * The closed list is the point: a route parameter, a session value or a
 * database row can only ever name one of these, never an arbitrary Socialite
 * driver. Every internal boundary passes the enum, not a string.
 */
enum SocialProvider: string
{
    case Google = 'google';
    case Facebook = 'facebook';

    public static function isValid(string $value): bool
    {
        return self::tryFrom($value) !== null;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $provider): string => $provider->value, self::cases());
    }

    /** The provider name as shown in UI copy and messages. */
    public function label(): string
    {
        return match ($this) {
            self::Google => 'Google',
            self::Facebook => 'Facebook',
        };
    }

    /**
     * The OAuth scopes requested at redirect time: the minimum that yields a
     * stable subject id, an email address and a display name. Nothing beyond
     * this is ever asked for, and nothing beyond it is read back.
     *
     * @return list<string>
     */
    public function scopes(): array
    {
        return match ($this) {
            self::Google => ['openid', 'profile', 'email'],
            self::Facebook => ['email'],
        };
    }
}
