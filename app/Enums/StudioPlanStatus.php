<?php

namespace App\Enums;

/**
 * Where a publication plan stands. A plan is made and edited as a Draft and
 * may be deleted while it is one; approval moves it on to Approved, its run
 * to Running and then Completed, and an administrator may cancel it. The
 * executor moves it on (docs/architecture/studio-plans.md, "Running a plan"):
 * Running once its first action is claimed, then Completed once its post is
 * published and nothing of it is left, or Cancelled when its post never will
 * be — whether its last actions ran or an administrator stopped what was left
 * of it in the Action queue.
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
