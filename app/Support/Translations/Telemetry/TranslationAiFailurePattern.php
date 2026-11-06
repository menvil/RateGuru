<?php

namespace App\Support\Translations\Telemetry;

/**
 * How one kind of provider failure is happening over a period — told from the
 * failed calls themselves, nothing else.
 *
 *  - Isolated: no series — failures with no five of them close together.
 *  - Burst:    five or more failed calls with the same code within ten
 *              minutes, at some point in the period.
 *  - Ongoing:  such a series that reaches into the last fifteen minutes.
 */
enum TranslationAiFailurePattern: string
{
    case Isolated = 'isolated';
    case Burst = 'burst';
    case Ongoing = 'ongoing';

    /** Failed calls with one code that make a series. */
    public const SERIES_CALLS = 5;

    /** The sliding window those calls must fall within. */
    public const SERIES_WINDOW_SECONDS = 600;

    /** How recent a series must reach to be still going on. */
    public const ONGOING_SECONDS = 900;

    public function label(): string
    {
        return match ($this) {
            self::Isolated => 'Isolated',
            self::Burst => 'Burst',
            self::Ongoing => 'Ongoing',
        };
    }
}
