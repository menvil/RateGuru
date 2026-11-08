<?php

namespace App\Enums;

/**
 * How a game is played. Only Versus is built; another mechanic arrives with
 * its own rounds' rules (app/Support/Games/<Mechanic>) and a case here.
 */
enum GameMechanic: string
{
    case Versus = 'versus';

    public function label(): string
    {
        return match ($this) {
            self::Versus => 'Versus',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Versus => 'Choose between two images.',
        };
    }

    /** How many options each round of the mechanic has. */
    public function optionCount(): int
    {
        return match ($this) {
            self::Versus => 2,
        };
    }
}
