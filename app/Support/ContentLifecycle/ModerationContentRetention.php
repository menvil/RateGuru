<?php

namespace App\Support\ContentLifecycle;

use InvalidArgumentException;

/**
 * Strict resolver for the FINALIZED moderation-content retention window
 * (docs/architecture/moderation-content-lifecycle.md).
 *
 * null result = DISABLED: finalized moderation removals are retained
 * indefinitely — the deliberate default. Disabled must never degrade to
 * "0 days": only an explicit `0` enables immediate eligibility, and any
 * invalid value (negative, decimal, garbage) throws so destructive
 * cleanup fails closed instead of running early.
 */
final class ModerationContentRetention
{
    public static function days(): ?int
    {
        $raw = config('content_lifecycle.moderation.content_retention_days');

        if ($raw === null || $raw === '') {
            return null;
        }

        if (is_int($raw) && $raw >= 0) {
            return $raw;
        }

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

        throw new InvalidArgumentException(sprintf(
            'content_lifecycle.moderation.content_retention_days must be empty (disabled) or an integer >= 0, got [%s]; refusing to compute retention.',
            is_scalar($raw) ? var_export($raw, true) : gettype($raw),
        ));
    }
}
