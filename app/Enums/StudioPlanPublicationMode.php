<?php

namespace App\Enums;

/**
 * When a plan's post is to be published: as soon as the plan runs, or at the
 * date the plan names — a future one, or a past one the post then carries as
 * its own.
 */
enum StudioPlanPublicationMode: string
{
    case Now = 'now';
    case AtDate = 'at_date';

    public function label(): string
    {
        return match ($this) {
            self::Now => 'Now',
            self::AtDate => 'At a date',
        };
    }
}
