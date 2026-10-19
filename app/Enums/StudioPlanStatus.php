<?php

namespace App\Enums;

/**
 * Where a publication plan stands. A plan is made and edited as a Draft and
 * may be deleted while it is one; approval moves it on to Approved, its run
 * to Running and then Completed, and an administrator may cancel it. Only
 * the Draft is anything a plan can be today: nothing approves, runs or
 * cancels a plan yet, so the other states have no way in.
 */
enum StudioPlanStatus: string
{
    case Draft = 'draft';
    case Approved = 'approved';
    case Running = 'running';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Approved => 'Approved',
            self::Running => 'Running',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
        };
    }
}
