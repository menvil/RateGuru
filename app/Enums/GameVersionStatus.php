<?php

namespace App\Enums;

/**
 * Whether a published version may still be given traffic. Whether it has any
 * is a matter of the current traffic periods, not of this status.
 */
enum GameVersionStatus: string
{
    case Active = 'active';
    case Retired = 'retired';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Retired => 'Archived',
        };
    }
}
