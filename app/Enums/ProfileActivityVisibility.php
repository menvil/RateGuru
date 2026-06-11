<?php

namespace App\Enums;

enum ProfileActivityVisibility: string
{
    case Private = 'private';
    case Public = 'public';

    // followers_only is reserved for future use

    public static function isValid(string $value): bool
    {
        return in_array($value, self::values(), true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
