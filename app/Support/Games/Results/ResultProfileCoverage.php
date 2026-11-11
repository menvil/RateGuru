<?php

namespace App\Support\Games\Results;

/**
 * Whether a set's result profiles give every player exactly one result. A
 * player matches anything from none to all of the scored rounds, so each
 * count from 0 to `$total` must fall in exactly one profile's range: none
 * left out, none shared, none beyond what a player can reach.
 *
 * Ranges count matches, not points, so the points a round is worth never
 * leaves a range covering a score nobody can have.
 */
final class ResultProfileCoverage
{
    /** The names suggested profiles take, in order of how much they matched. */
    public const SUGGESTED = [
        ['name' => 'Wild Card', 'description' => 'You see things differently.', 'icon' => 'shuffle'],
        ['name' => 'Independent', 'description' => 'You have your own taste.', 'icon' => 'compass'],
        ['name' => 'Sharp Eye', 'description' => 'You read the crowd well.', 'icon' => 'eye'],
        ['name' => 'Oracle', 'description' => 'You matched every choice.', 'icon' => 'sparkles'],
    ];

    /**
     * @param  list<ResultProfileRange>  $profiles
     * @return list<string>
     */
    public static function issues(array $profiles, int $total): array
    {
        if ($profiles === []) {
            return ['No result profiles yet.'];
        }

        $issues = [];
        $covering = array_fill(0, max(0, $total) + 1, []);

        foreach ($profiles as $profile) {
            if ($profile->min > $profile->max) {
                $issues[] = "{$profile->name}: the range starts above its end.";

                continue;
            }

            if ($profile->max > $total) {
                $issues[] = sprintf('%s goes up to %s, but the most a player can match is %d.', $profile->name, self::matches($profile->max), $total);
            }

            for ($count = max(0, $profile->min); $count <= min($profile->max, $total); $count++) {
                $covering[$count][] = $profile->name;
            }
        }

        foreach (self::runs($covering, fn (array $names): bool => count($names) > 1) as [$from, $to, $names]) {
            $issues[] = sprintf('%s overlap at %s.', self::listOf($names), self::span($from, $to));
        }

        foreach (self::runs($covering, fn (array $names): bool => $names === []) as [$from, $to]) {
            $issues[] = sprintf('No profile covers %s.', self::span($from, $to));
        }

        return $issues;
    }

    /**
     * The profile a number of matches falls in, by its place in the list;
     * null when none does.
     *
     * @param  list<ResultProfileRange>  $profiles
     */
    public static function profileFor(array $profiles, int $matches): ?int
    {
        foreach ($profiles as $index => $profile) {
            if ($matches >= $profile->min && $matches <= $profile->max) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Ranges that cover 0 to `$total` exactly once, for up to four profiles:
     * the top one for matching everything, the next for one short, and the
     * rest of the counts split between the lower two.
     *
     * @return list<array{min: int, max: int}>
     */
    public static function suggestedRanges(int $total): array
    {
        $total = max(1, $total);

        return match (true) {
            $total === 1 => [['min' => 0, 'max' => 0], ['min' => 1, 'max' => 1]],
            $total === 2 => [['min' => 0, 'max' => 0], ['min' => 1, 'max' => 1], ['min' => 2, 'max' => 2]],
            default => (function () use ($total): array {
                $lowerEnd = intdiv($total - 2, 2);

                return [
                    ['min' => 0, 'max' => $lowerEnd],
                    ['min' => $lowerEnd + 1, 'max' => $total - 2],
                    ['min' => $total - 1, 'max' => $total - 1],
                    ['min' => $total, 'max' => $total],
                ];
            })(),
        };
    }

    /**
     * Which of SUGGESTED each suggested range takes: the lowest is always the
     * Wild Card and the highest the Oracle.
     *
     * @return list<int>
     */
    public static function suggestedNames(int $total): array
    {
        return match (count(self::suggestedRanges($total))) {
            2 => [0, 3],
            3 => [0, 1, 3],
            default => [0, 1, 2, 3],
        };
    }

    /**
     * Runs of consecutive counts that match, with the names covering them.
     *
     * @param  array<int, list<string>>  $covering
     * @param  callable(list<string>): bool  $matches
     * @return list<array{0: int, 1: int, 2: list<string>}>
     */
    private static function runs(array $covering, callable $matches): array
    {
        $runs = [];
        $start = null;

        foreach ($covering as $count => $names) {
            $continues = $start !== null && $covering[$start] === $names;

            if ($start !== null && (! $matches($names) || ! $continues)) {
                $runs[] = [$start, $count - 1, $covering[$start]];
                $start = null;
            }

            if ($start === null && $matches($names)) {
                $start = $count;
            }
        }

        if ($start !== null) {
            $runs[] = [$start, array_key_last($covering), $covering[$start]];
        }

        return $runs;
    }

    /** @param  list<string>  $names */
    private static function listOf(array $names): string
    {
        $names = array_values(array_unique($names));
        $last = array_pop($names);

        return $names === [] ? (string) $last : implode(', ', $names).' and '.$last;
    }

    private static function span(int $from, int $to): string
    {
        return $from === $to ? self::matches($from) : "{$from}–{$to} matches";
    }

    private static function matches(int $count): string
    {
        return $count === 1 ? '1 match' : "{$count} matches";
    }
}
