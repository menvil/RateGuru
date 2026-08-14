<?php

namespace App\Support\ContentLifecycle;

/**
 * Strict resolver for the FINALIZED moderation-content retention window
 * (docs/architecture/moderation-content-lifecycle.md). null = DISABLED —
 * the deliberate default: finalized moderation removals are retained
 * indefinitely, and disabled must never degrade to "0 days".
 */
final class ModerationContentRetention
{
    public static function days(): ?int
    {
        return RetentionDays::parseOrNull(
            config('content_lifecycle.moderation.content_retention_days'),
            'content_lifecycle.moderation.content_retention_days',
        );
    }
}
