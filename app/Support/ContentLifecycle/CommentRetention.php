<?php

namespace App\Support\ContentLifecycle;

use InvalidArgumentException;

/**
 * Strict, fail-closed resolver for the author-deleted comment retention
 * window (docs/architecture/comment-lifecycle.md). Same contract as
 * PostRetention: integer >= 0 or unsigned digit string only; anything else
 * throws — a typo must stop the daily cleanup, never collapse to 0.
 */
final class CommentRetention
{
    public static function authorDeleteDays(): int
    {
        $raw = config('content_lifecycle.comments.author_delete_retention_days');

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
            'content_lifecycle.comments.author_delete_retention_days must be an integer >= 0, got [%s]; refusing to compute retention.',
            is_scalar($raw) ? var_export($raw, true) : gettype($raw),
        ));
    }
}
