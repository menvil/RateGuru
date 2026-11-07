<?php

namespace App\Enums;

/**
 * How often a Persona puts emoji in what it writes. A closed set: the column
 * accepts nothing else, whatever a request carries.
 */
enum PersonaEmojiFrequency: string
{
    case None = 'none';
    case Occasional = 'occasional';
    case Frequent = 'frequent';

    public function label(): string
    {
        return match ($this) {
            self::None => 'None',
            self::Occasional => 'Occasional',
            self::Frequent => 'Frequent',
        };
    }

    /** How often, as a model writing in the Persona's voice is told it. */
    public function inWords(): string
    {
        return match ($this) {
            self::None => 'never',
            self::Occasional => 'sometimes',
            self::Frequent => 'often',
        };
    }
}
