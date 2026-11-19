<?php

namespace App\Enums;

/**
 * What the versions of a traffic period are compared by, in the order the
 * screens list them: each a share of the visitors assigned to the version
 * (PeriodComparison). The period's page in Growth → Experiments lists all
 * but the full result, which Analytics → Experiments adds. Never stored.
 */
enum PeriodMetric: string
{
    case Starts = 'started';
    case Completions = 'completed';
    case ResultViewed = 'result_viewed';
    case Emails = 'emailed';
    case Registrations = 'accounts';
    case Activated = 'activated';
    case Goal = 'goal';

    public function label(): string
    {
        return match ($this) {
            self::Starts => 'Game starts',
            self::Completions => 'Completions',
            self::ResultViewed => 'Saw the full result',
            self::Emails => 'Email captured',
            self::Registrations => 'Registrations',
            self::Activated => 'Activated · 7 days',
            self::Goal => 'Goal reached',
        };
    }
}
