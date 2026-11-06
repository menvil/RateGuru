<?php

namespace App\Support\Posts;

use App\Support\ContentLifecycle\RetentionDays;

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
        return RetentionDays::parse(
            config('posts.author_delete_retention_days'),
            'posts.author_delete_retention_days',
        );
    }
}
