<?php

namespace App\Support\ContentLifecycle;

use InvalidArgumentException;

/**
 * Shared strict parsing for retention-day config values: integer >= 0 or
 * unsigned digit string that fits in an int; anything else throws so
 * destructive cleanup fails closed instead of silently collapsing to 0.
 * The public resolvers (PostRetention, CommentRetention,
 * ModerationContentRetention) stay the semantic boundaries — this is the
 * single maintenance point for the parsing rules.
 */
final class RetentionDays
{
    public static function parse(mixed $raw, string $context): int
    {
        $parsed = self::tryParse($raw);

        if ($parsed !== null) {
            return $parsed;
        }

        throw new InvalidArgumentException(sprintf(
            '%s must be an integer >= 0, got [%s]; refusing to compute retention.',
            $context,
            is_scalar($raw) ? var_export($raw, true) : gettype($raw),
        ));
    }

    /** Empty/null means "disabled" and returns null; invalid values throw. */
    public static function parseOrNull(mixed $raw, string $context): ?int
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        $parsed = self::tryParse($raw);

        if ($parsed !== null) {
            return $parsed;
        }

        throw new InvalidArgumentException(sprintf(
            '%s must be empty (disabled) or an integer >= 0, got [%s]; refusing to compute retention.',
            $context,
            is_scalar($raw) ? var_export($raw, true) : gettype($raw),
        ));
    }

    private static function tryParse(mixed $raw): ?int
    {
        if (is_int($raw) && $raw >= 0) {
            return $raw;
        }

        // env() always yields strings from a real environment; accept only
        // unsigned digit strings that fit in an int — an over-PHP_INT_MAX
        // string would silently saturate on cast.
        if (is_string($raw) && ctype_digit($raw)) {
            $normalized = ltrim($raw, '0');
            $normalized = $normalized === '' ? '0' : $normalized;
            $max = (string) PHP_INT_MAX;

            $fitsInt = strlen($normalized) < strlen($max)
                || (strlen($normalized) === strlen($max) && strcmp($normalized, $max) <= 0);

            if ($fitsInt) {
                return (int) $normalized;
            }
        }

        return null;
    }
}
