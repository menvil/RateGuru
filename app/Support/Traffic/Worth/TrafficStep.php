<?php

namespace App\Support\Traffic\Worth;

use App\Enums\TrafficEvent;

/**
 * The steps a visit's worth is worked out for — opened, started, every round
 * but the last (round_1 … round_{N−1}), completed, the steps after the result
 * and on the site, signing up and the account itself. An event names its
 * step, except that Halfway is the middle round of the game it happened in
 * (the last round is "completed") and the flow's goal is the goal's own step.
 */
final class TrafficStep
{
    /** @var list<string> */
    public const array AFTER_THE_RESULT = ['email', 'shared', 'replayed', 'next_game', 'page', 'feed'];

    /** @var list<string> */
    public const array ON_THE_SITE = ['post_opened', 'post_voted', 'post_rated', 'feed_voted'];

    /** @var list<string> */
    public const array ACCOUNTS = ['signup', 'account', 'login'];

    public static function round(int $answered, int $rounds): string
    {
        return $answered >= $rounds ? 'completed' : 'round_'.max(1, $answered);
    }

    public static function halfway(int $rounds): string
    {
        return self::round((int) ceil(max(1, $rounds) / 2), max(1, $rounds));
    }

    /** @return list<string> Every step of a game of the given number of rounds, in order. */
    public static function all(int $rounds): array
    {
        $playing = ['opened', 'started'];

        for ($round = 1; $round < $rounds; $round++) {
            $playing[] = 'round_'.$round;
        }

        return [...$playing, 'completed', ...self::AFTER_THE_RESULT, ...self::ON_THE_SITE, ...self::ACCOUNTS];
    }

    /** The step an event stands for; null for those that are not a step (the goal, the sign-up form, the bonuses). */
    public static function ofEvent(TrafficEvent $event, int $rounds): ?string
    {
        return match ($event) {
            TrafficEvent::Halfway => self::halfway($rounds),
            TrafficEvent::Goal, TrafficEvent::SignupForm, TrafficEvent::PostPublished, TrafficEvent::Returned => null,
            default => $event->value,
        };
    }

    public static function isRound(string $step): bool
    {
        return preg_match('/^round_\d+$/', $step) === 1;
    }
}
