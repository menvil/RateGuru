<?php

namespace App\Enums;

enum MediaPurgeOutcome: string
{
    case Purged = 'purged';
    case NotEligible = 'not_eligible';
    case Locked = 'locked';
    case AlreadyGone = 'already_gone';
    case Failed = 'failed';
}
