<?php

use App\Support\Analytics\ActivityDay;
use Carbon\CarbonImmutable;

/*
 * The day of an account's life a moment falls on: whole UTC calendar days
 * from the day it signed up, however late on that day it signed up, and
 * whatever time zone the moments are given in.
 */

it('counts whole UTC calendar days from the day of signing up', function (string $signedUp, string $at, int $day) {
    expect(ActivityDay::number(CarbonImmutable::parse($signedUp), CarbonImmutable::parse($at)))->toBe($day);
})->with([
    'later the same day' => ['2026-11-17 00:00:00+00:00', '2026-11-17 23:59:59+00:00', 0],
    'a second after midnight' => ['2026-11-17 23:59:59+00:00', '2026-11-18 00:00:00+00:00', 1],
    'almost two days later, still the next day' => ['2026-11-17 00:00:00+00:00', '2026-11-18 23:59:59+00:00', 1],
    'the seventh day, at its last second' => ['2026-11-17 12:00:00+00:00', '2026-11-24 23:59:59+00:00', 7],
    'the eighth day, at its first second' => ['2026-11-17 12:00:00+00:00', '2026-11-25 00:00:00+00:00', 8],
    'across the end of a month and a year' => ['2026-12-31 22:00:00+00:00', '2027-01-01 01:00:00+00:00', 1],
    'across a leap day' => ['2028-02-28 10:00:00+00:00', '2028-03-01 10:00:00+00:00', 2],
    'before the day of signing up' => ['2026-11-17 00:30:00+00:00', '2026-11-16 23:30:00+00:00', -1],
]);

it('reads the days in UTC whatever time zone the moments come in', function () {
    // 23:30 in New York on the 17th is 04:30 UTC on the 18th; 01:00 in Berlin on the 18th is 00:00 UTC on the 18th.
    $signedUp = CarbonImmutable::parse('2026-11-17 23:30:00', 'America/New_York');

    expect(ActivityDay::number($signedUp, CarbonImmutable::parse('2026-11-18 01:00:00', 'Europe/Berlin')))->toBe(0)
        ->and(ActivityDay::number($signedUp, CarbonImmutable::parse('2026-11-19 00:59:59', 'Europe/Berlin')))->toBe(0)
        ->and(ActivityDay::number($signedUp, CarbonImmutable::parse('2026-11-19 01:00:00', 'Europe/Berlin')))->toBe(1)
        ->and(ActivityDay::of(CarbonImmutable::parse('2026-11-17 23:30:00', 'America/New_York')))->toBe('2026-11-18');
});

it('activates on day 1 to the last activation day only', function (?int $day, bool $activates) {
    expect(ActivityDay::activates($day, 7))->toBe($activates);
})->with([
    'the day of signing up' => [0, false],
    'the next day' => [1, true],
    'the seventh day' => [7, true],
    'the eighth day' => [8, false],
    'a day before signing up' => [-1, false],
    'an unnumbered day' => [null, false],
]);
