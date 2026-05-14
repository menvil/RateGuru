<?php

namespace App\Enums;

enum UserStatus: string
{
    case Active = 'active';
    case Limited = 'limited';
    case Banned = 'banned';
    case Shadowbanned = 'shadowbanned';

    public function canCreateContent(): bool
    {
        return $this === self::Active;
    }
}
