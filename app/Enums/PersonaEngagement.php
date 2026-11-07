<?php

namespace App\Enums;

/**
 * How readily a Persona would reply on its own under comments. It says
 * nothing about the account's lifecycle: Off stops only the replies the
 * Persona would write of its own accord, never what an approved publication
 * plan has it do, and a Persona's standing is its UserStatus alone.
 */
enum PersonaEngagement: string
{
    case Off = 'off';
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';

    public function label(): string
    {
        return match ($this) {
            self::Off => 'Off',
            self::Low => 'Low',
            self::Normal => 'Normal',
            self::High => 'High',
        };
    }
}
