<?php

namespace App\Support\Traffic\Worth;

/**
 * How a step of a visit's worth reads in the admin: its name — a round as
 * answered, the last one as the game finished — what it means, its icon, and
 * the part of the play it belongs to: playing, after the result, on the site
 * the flow sends the visitor to, or the account.
 */
final class StepLabels
{
    public const string PLAYING = 'playing';

    public const string AFTER_THE_RESULT = 'after_the_result';

    public const string ON_THE_SITE = 'on_the_site';

    public const string ACCOUNTS = 'accounts';

    /** @var array<string, array{0: string, 1: string, 2: string}> step => [name, note, icon] */
    private const array STEPS = [
        'opened' => ['Page opened', 'The game page ran in a real browser', 'file'],
        'started' => ['Game started', 'Pressed Start', 'play'],
        'email' => ['Email left', 'Email Capture submitted', 'mail'],
        'shared' => ['Result shared', 'First share click', 'share-2'],
        'replayed' => ['Played again', 'Another attempt started', 'rotate-ccw'],
        'next_game' => ['Next game started', 'Started the suggested game', 'arrow-right'],
        'page' => ['Went to the page', 'Clicked Go to Page', 'arrow-up-right'],
        'feed' => ['Went to the feed', 'Clicked Go to the feed', 'home'],
        'post_opened' => ['Opened the post', 'Followed Go to Post or Go to a post on Full Result', 'newspaper'],
        'post_voted' => ['Voted on the post', 'Up or down vote on that post within 30 minutes', 'thumbs-up'],
        'post_rated' => ['Rated the post', 'Answered a rating group the post asks for', 'star'],
        'feed_voted' => ['Voted in the feed', '3 votes in the feed within 30 minutes of the game', 'list-checks'],
        'signup' => ['Signed up', 'Signed up on any page within 7 days of the play, email not confirmed yet', 'user-plus'],
        'account' => ['Account confirmed', 'Email confirmed, or Google or Facebook', 'user'],
        'login' => ['Existing account signed in', 'Already had an account', 'log-in'],
    ];

    public static function name(string $step, int $rounds): string
    {
        return match (true) {
            TrafficStep::isRound($step) => 'Round '.self::roundOf($step).' answered',
            $step === 'completed' => 'Round '.max(1, $rounds).' answered · game finished',
            default => self::STEPS[$step][0] ?? ucfirst(str_replace('_', ' ', $step)),
        };
    }

    public static function note(string $step, int $rounds): string
    {
        return match (true) {
            $step === 'round_1' => 'The first pick',
            TrafficStep::isRound($step) => 'Reached round '.self::roundOf($step).' of '.max(1, $rounds),
            $step === 'completed' => 'The last round finishes the game',
            default => self::STEPS[$step][1] ?? '',
        };
    }

    public static function icon(string $step): string
    {
        return match (true) {
            TrafficStep::isRound($step) => 'list',
            $step === 'completed' => 'flag',
            default => self::STEPS[$step][2] ?? 'circle',
        };
    }

    public static function section(string $step): string
    {
        return match (true) {
            in_array($step, TrafficStep::AFTER_THE_RESULT, true) => self::AFTER_THE_RESULT,
            in_array($step, TrafficStep::ON_THE_SITE, true) => self::ON_THE_SITE,
            in_array($step, TrafficStep::ACCOUNTS, true) => self::ACCOUNTS,
            default => self::PLAYING,
        };
    }

    private static function roundOf(string $step): int
    {
        return (int) substr($step, strlen('round_'));
    }
}
