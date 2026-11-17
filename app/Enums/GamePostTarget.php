<?php

namespace App\Enums;

/** Which post a flow sends the visitor to. */
enum GamePostTarget: string
{
    case Chosen = 'chosen';
    case LastPick = 'last_pick';
    case Fresh = 'fresh';

    public function label(): string
    {
        return match ($this) {
            self::Chosen => 'A post you link to',
            self::LastPick => 'The post they picked in the last round',
            self::Fresh => 'A fresh post they haven’t rated',
        };
    }

    public function help(): string
    {
        return match ($this) {
            self::Chosen => 'The same post for everyone',
            self::LastPick => 'Different for every player',
            self::Fresh => 'The newest post outside the game, one they haven’t voted on',
        };
    }
}
