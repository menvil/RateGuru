<?php

namespace App\Support\Posts;

use InvalidArgumentException;

/**
 * Strict, fail-closed resolver for the author-deletion retention window
 * (docs/architecture/post-lifecycle.md). The config layer deliberately
 * passes the raw POST_AUTHOR_DELETE_RETENTION_DAYS value through without
 * coercion: a negative or non-numeric value must stop the retention purge
 * (and every retention computation) with an exception, never silently
 * collapse to 0 — (int) "foo" and max(0, -30) would both turn an operator
 * typo into an immediately-expired restore window feeding a destructive
 * daily purge.
 */
final class PostRetention
{
    public static function days(): int
    {
        $raw = config('posts.author_delete_retention_days');

        if (is_int($raw) && $raw >= 0) {
            return $raw;
        }

        // env() always yields strings from a real environment; accept only
        // unsigned integer strings ("0", "30") that fit in an int —
        // an over-PHP_INT_MAX string would silently saturate on cast.
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
            'posts.author_delete_retention_days must be an integer >= 0, got [%s]; refusing to compute retention.',
            is_scalar($raw) ? var_export($raw, true) : gettype($raw),
        ));
    }
}
