<?php

namespace App\Enums;

/** The two groups of visitors traffic is shared out to separately. */
enum GameAudience: string
{
    case Anonymous = 'anonymous';
    case Authenticated = 'authenticated';

    public function label(): string
    {
        return match ($this) {
            self::Anonymous => 'Anonymous visitors',
            self::Authenticated => 'Logged-in players',
        };
    }
}
