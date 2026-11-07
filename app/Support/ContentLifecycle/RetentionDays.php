<?php

namespace App\Support\ContentLifecycle;

use InvalidArgumentException;

/**
 * Shared strict parsing for retention-day config values: integer from 0 to
 * MAX_DAYS, or unsigned digit string of one; anything else throws so
 * destructive cleanup fails closed instead of silently collapsing to 0.
 * The public resolvers (PostRetention, CommentRetention,
 * ModerationContentRetention) stay the semantic boundaries — this is the
 * single maintenance point for the parsing rules.
 */
final class RetentionDays
{
    /**
     * A century: as far back as any retention can sensibly reach, and a
     * bound below the numbers that break the date — one near PHP_INT_MAX,
     * where (int) saturates any longer number, subtracted from today wraps
     * round to the present, and a purge would take everything instead of
     * nothing.
     */
    public const MAX_DAYS = 36500;

    public static function parse(mixed $raw, string $context): int
    {
        $parsed = self::tryParse($raw);

        if ($parsed !== null) {
            return $parsed;
        }

        throw new InvalidArgumentException(sprintf(
            '%s must be an integer from 0 to '.self::MAX_DAYS.', got [%s]; refusing to compute retention.',
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
            '%s must be empty (disabled) or an integer from 0 to '.self::MAX_DAYS.', got [%s]; refusing to compute retention.',
            $context,
            is_scalar($raw) ? var_export($raw, true) : gettype($raw),
        ));
    }

    /**
     * The number of days, or null for anything else: a negative or larger
     * than MAX_DAYS, a non-integer, a string that is not plain digits.
     * Shared by the purge commands for their --older-than overrides.
     */
    public static function tryParse(mixed $raw): ?int
    {
        if (is_int($raw)) {
            return $raw >= 0 && $raw <= self::MAX_DAYS ? $raw : null;
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

            if ($fitsInt && (int) $normalized <= self::MAX_DAYS) {
                return (int) $normalized;
            }
        }

        return null;
    }
}
