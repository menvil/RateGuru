<?php

namespace App\Support\Games\Analytics;

/**
 * How far the visitors of a game got, over one audience and one window: who
 * opened it, the attempts they started and completed, the emails and accounts
 * those attempts led to, the attempts whose result was shared, and the people
 * who started more than once.
 *
 * Visitors and players are people, counted once each; started and completed
 * are attempts, counted from the attempts started in the window — a cohort, so
 * an attempt finished the day after the window still counts as completed.
 */
final readonly class GameFunnel
{
    public function __construct(
        /** People who opened the game: anonymous visitors by their cookie, players by their account. */
        public int $visitors = 0,
        public int $started = 0,
        public int $completed = 0,
        /** Emails left by the started attempts. */
        public int $emails = 0,
        /** New accounts made from a started attempt, each person once. */
        public int $registered = 0,
        /** Existing accounts signed in from a started attempt, each person once. */
        public int $signedIn = 0,
        /** Started attempts whose result was shared at least once. */
        public int $shared = 0,
        /** People who started more than one attempt. */
        public int $replayers = 0,
    ) {}

    /** A whole share of a part, to one decimal place; null with nothing to divide by. */
    public static function rate(int $part, int $whole): ?float
    {
        return $whole > 0 ? round($part * 100 / $whole, 1) : null;
    }

    public function isEmpty(): bool
    {
        return $this->visitors === 0 && $this->started === 0;
    }
}
