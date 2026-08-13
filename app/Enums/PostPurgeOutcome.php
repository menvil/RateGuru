<?php

namespace App\Enums;

enum PostPurgeOutcome: string
{
    case Purged = 'purged';
    case WouldPurge = 'would_purge';
    case NotExpired = 'not_expired';
    case ModerationHold = 'moderation_hold';
    case InvalidState = 'invalid_state';
    case AlreadyGone = 'already_gone';
    case Failed = 'failed';
}
