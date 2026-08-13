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
        // unsigned integer strings ("0", "30"), nothing else.
        if (is_string($raw) && ctype_digit($raw)) {
            return (int) $raw;
        }

        throw new InvalidArgumentException(sprintf(
            'posts.author_delete_retention_days must be an integer >= 0, got [%s]; refusing to compute retention.',
            is_scalar($raw) ? var_export($raw, true) : gettype($raw),
        ));
    }
}
