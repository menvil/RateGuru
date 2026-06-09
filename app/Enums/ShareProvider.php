<?php

namespace App\Enums;

enum ShareProvider: string
{
    case CopyLink = 'copy_link';
    case Native = 'native';
    case Facebook = 'facebook';
    case X = 'x';
    case Telegram = 'telegram';
    case WhatsApp = 'whatsapp';
    case Reddit = 'reddit';
    case Pinterest = 'pinterest';
    case Email = 'email';

    public static function isValid(string $value): bool
    {
        return self::tryFrom($value) !== null;
    }

    public static function urlProviders(): array
    {
        return [
            self::Facebook,
            self::X,
            self::Telegram,
            self::WhatsApp,
            self::Reddit,
            self::Pinterest,
            self::Email,
        ];
    }
}
