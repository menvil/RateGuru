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

    /** The admin kit icon a game of the mechanic is drawn with. */
    public function icon(): string
    {
        return match ($this) {
            self::Versus => 'columns-2',
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
