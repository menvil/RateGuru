<?php

namespace App\Enums;

/** Who sees a flow step; the others skip it. */
enum GameStepAudience: string
{
    case All = 'all';
    case Anonymous = 'anonymous';
    case Authenticated = 'authenticated';

    public function label(): string
    {
        return match ($this) {
            self::All => 'All visitors',
            self::Anonymous => 'Anonymous',
            self::Authenticated => 'Logged in',
        };
    }

    public function includes(GameAudience $audience): bool
    {
        return match ($this) {
            self::All => true,
            self::Anonymous => $audience === GameAudience::Anonymous,
            self::Authenticated => $audience === GameAudience::Authenticated,
        };
    }
}
