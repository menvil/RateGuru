<?php

namespace App\Enums;

enum ModerationPurgeOutcome: string
{
    case Purged = 'purged';
    case WouldPurge = 'would_purge';
    case RetentionDisabled = 'retention_disabled';
    case NotExpired = 'not_expired';
    case ModerationHold = 'moderation_hold';
    case StructuralAnchor = 'structural_anchor';
    case ParentPostHold = 'parent_post_hold';
    case InvalidState = 'invalid_state';
    case AlreadyGone = 'already_gone';
    case Failed = 'failed';
}
