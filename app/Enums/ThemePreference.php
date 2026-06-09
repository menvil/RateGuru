<?php

namespace App\Enums;

enum ThemePreference: string
{
    case System = 'system';
    case Light = 'light';
    case Dark = 'dark';

    public static function isValid(string $value): bool
    {
        return in_array($value, array_column(self::cases(), 'value'), true);
    }

    public static function fromStringOrDefault(string $value, self $default = self::System): self
    {
        return self::tryFrom($value) ?? $default;
    }
}
