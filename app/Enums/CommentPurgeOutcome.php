<?php

namespace App\Enums;

enum CommentPurgeOutcome: string
{
    case Purged = 'purged';
    case WouldPurge = 'would_purge';
    case NotExpired = 'not_expired';
    case StructuralAnchor = 'structural_anchor';
    case OpenReportHold = 'open_report_hold';
    case PostRetentionHold = 'post_retention_hold';
    case PostModerationHold = 'post_moderation_hold';
    case InvalidState = 'invalid_state';
    case AlreadyGone = 'already_gone';
    case Failed = 'failed';
}
