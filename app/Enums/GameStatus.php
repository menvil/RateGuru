<?php

namespace App\Enums;

/**
 * Where a game stands with its visitors. A game is made as a Draft; it goes
 * Live once a published version has a share of traffic, may be Paused (no new
 * attempts, started ones finish) and Archived (over, its numbers kept). Which
 * moves are allowed is ChangeGameStatusAction's to decide.
 */
enum GameStatus: string
{
    case Draft = 'draft';
    case Live = 'live';
    case Paused = 'paused';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Live => 'Live',
            self::Paused => 'Paused',
            self::Archived => 'Archived',
        };
    }

    /** Whether a visitor may start a new attempt. */
    public function acceptsNewAttempts(): bool
    {
        return $this === self::Live;
    }

    /** Whether an attempt already started may still be played to its end. */
    public function letsStartedAttemptsFinish(): bool
    {
        return $this === self::Live || $this === self::Paused;
    }
}
