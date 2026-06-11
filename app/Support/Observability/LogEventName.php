<?php

namespace App\Support\Observability;

final class LogEventName
{
    public static function isValid(string $name): bool
    {
        if ($name === '') {
            return false;
        }

        // Must contain at least one dot (domain.action)
        if (! str_contains($name, '.')) {
            return false;
        }

        // Lowercase, dots, underscores, digits only — no spaces or uppercase
        return preg_match('/^[a-z0-9_]+(\.[a-z0-9_]+)+$/', $name) === 1;
    }
}
