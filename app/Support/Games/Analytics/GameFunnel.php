<?php

namespace App\Support\Games\Analytics;

/**
 * How far the visitors of a game got, over one audience and one window: who
 * opened it, the attempts they started and completed, the emails and accounts
 * those attempts led to, the attempts whose result was shared, and the people
 * who started more than once.
 *
 * Visitors and players are people, counted once each; started and completed
 * are attempts — plays, a replay one more — counted from the attempts started
 * in the window, a cohort, so an attempt finished the day after the window
 * still counts as completed. Starters, finishers, emailers, sharers and the
 * people who began signing up are people again, from those attempts: what a
 * funnel of people shows.
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
        /** People who started at least one attempt. */
        public int $starters = 0,
        /** People who completed at least one attempt. */
        public int $finishers = 0,
        /** People who left an email on an attempt. */
        public int $emailers = 0,
        /** People who shared the result of an attempt. */
        public int $sharers = 0,
        /** People who began signing up, or logging in, from an attempt. */
        public int $signUpStarters = 0,
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
