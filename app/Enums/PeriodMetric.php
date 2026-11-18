<?php

namespace App\Enums;

/**
 * What the versions of a traffic period are compared by, in the order the
 * period's page lists them: each a share of the visitors assigned to the
 * version (PeriodComparison). Never stored.
 */
enum PeriodMetric: string
{
    case Starts = 'started';
    case Completions = 'completed';
    case Emails = 'emailed';
    case Registrations = 'accounts';
    case Activated = 'activated';
    case Goal = 'goal';

    public function label(): string
    {
        return match ($this) {
            self::Starts => 'Game starts',
            self::Completions => 'Completions',
            self::Emails => 'Email captured',
            self::Registrations => 'Registrations',
            self::Activated => 'Activated · 7 days',
            self::Goal => 'Goal reached',
        };
    }
}
