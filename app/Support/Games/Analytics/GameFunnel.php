<?php

namespace App\Support\Games\Analytics;

/**
 * How far the visitors of a game got, over one audience and one window: who
 * opened it, the attempts they started and completed, the emails and accounts
 * those attempts led to, the attempts whose result was shared, the people who
 * started more than once, and where the flow sent them on from the game — a
 * post, the feed, a page or the next game — and what they did there: on a
 * post, the way from its sign-in form to a confirmed account as well.
 *
 * Visitors and players are people, counted once each; started and completed
 * are attempts — plays, a replay one more — counted from the attempts started
 * in the window, a cohort, so an attempt finished the day after the window
 * still counts as completed. Starters, finishers, emailers, sharers, the
 * people who began signing up and the people a way on sent somewhere are
 * people again, from those attempts: what a funnel of people shows.
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
        /** Started attempts that reached their flow's goal, however late. */
        public int $goalReached = 0,
        /** People who reached their flow's goal at least once. */
        public int $goalPeople = 0,
        /** People the flow's way on sent to a post: Full Result going on to one, or Go to Post. */
        public int $wentToPost = 0,
        /** People shown the sign-in or sign-up form within 30 minutes of a way on sending them to a post, or who signed up there. */
        public int $sawSignInOnPost = 0,
        /** People whose new account was made on that post within 30 minutes, confirmed or not yet. */
        public int $signedUpOnPost = 0,
        /** Of those, the people whose account was confirmed. */
        public int $confirmedOnPost = 0,
        /** People who voted on the post a way on sent them to, within 30 minutes. */
        public int $votedOnPost = 0,
        /** People who answered a rating group the step asked for on that post, within 30 minutes. */
        public int $ratedPost = 0,
        /** People the flow's way on sent to the feed: Full Result going on to it, or Go to Feed. */
        public int $wentToFeed = 0,
        /** People who voted three times after a way on sent them to the feed, within 30 minutes. */
        public int $votedInFeed = 0,
        /** People the flow's way on sent to a page: Full Result going on to one, or Go to Page. */
        public int $wentToPage = 0,
        /** People the Next Game step sent to the game it suggests. */
        public int $wentToNextGame = 0,
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
