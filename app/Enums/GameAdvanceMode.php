<?php

namespace App\Enums;

/** How the game moves on from a revealed pair: by itself after a delay, or by button. */
enum GameAdvanceMode: string
{
    case Auto = 'auto';
    case Button = 'button';

    public function label(): string
    {
        return match ($this) {
            self::Auto => 'Automatic',
            self::Button => 'By button',
        };
    }
}
