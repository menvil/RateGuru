<?php

namespace App\Enums;

enum UnconfirmedAccountPurgeOutcome: string
{
    case Purged = 'purged';
    case WouldPurge = 'would_purge';
    case NotEligible = 'not_eligible';
    case AlreadyGone = 'already_gone';
    case Failed = 'failed';
}
