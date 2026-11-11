<?php

namespace App\Enums;

/**
 * How a scored round decides its point: by the crowd — the post with the
 * larger share of upvotes on the site — or by the option an administrator
 * marked correct.
 */
enum GameRoundMode: string
{
    case Crowd = 'crowd';
    case Objective = 'objective';

    public function label(): string
    {
        return match ($this) {
            self::Crowd => 'Crowd preference',
            self::Objective => 'Objective answer',
        };
    }
}
