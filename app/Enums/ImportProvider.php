<?php

namespace App\Enums;

enum ImportProvider: string
{
    case DirectImage = 'direct_image';
    case OpenGraph = 'open_graph';
    case Facebook = 'facebook';
    case Instagram = 'instagram';
    case X = 'x';
    case Pinterest = 'pinterest';
    case Unsupported = 'unsupported';

    public static function isValid(string $value): bool
    {
        return self::tryFrom($value) !== null;
    }

    public static function socialProviders(): array
    {
        return [
            self::Facebook,
            self::Instagram,
            self::X,
            self::Pinterest,
        ];
    }

    public function isBestEffort(): bool
    {
        return in_array($this, self::socialProviders(), true);
    }
}
