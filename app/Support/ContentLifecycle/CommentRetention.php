<?php

namespace App\Support\ContentLifecycle;

/**
 * Strict, fail-closed resolver for the author-deleted comment retention
 * window (docs/architecture/comment-lifecycle.md).
 */
final class CommentRetention
{
    public static function authorDeleteDays(): int
    {
        return RetentionDays::parse(
            config('content_lifecycle.comments.author_delete_retention_days'),
            'content_lifecycle.comments.author_delete_retention_days',
        );
    }
}
