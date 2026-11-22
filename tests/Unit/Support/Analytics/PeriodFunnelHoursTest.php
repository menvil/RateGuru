<?php

use App\Support\Analytics\PeriodFunnelHours;
use Carbon\CarbonImmutable;

/*
 * The hours a traffic period's funnel is summed by: a moment's UTC hour,
 * whatever time zone it comes in; an hour open while it or the next is the
 * current one; and the last hour that can be counted, closed and settled ten
 * minutes since.
 */

it('takes the UTC hour a moment is in', function (string $moment, string $hour) {
    expect(PeriodFunnelHours::hourOf(CarbonImmutable::parse($moment))->toDateTimeString())->toBe($hour);
})->with([
    'its first second' => ['2026-11-18 10:00:00', '2026-11-18 10:00:00'],
    'its last second' => ['2026-11-18 10:59:59', '2026-11-18 10:00:00'],
    'in another time zone' => ['2026-11-18 12:30:00+02:00', '2026-11-18 10:00:00'],
    'across midnight' => ['2026-11-18 00:30:00+01:00', '2026-11-17 23:00:00'],
]);

it('keeps an hour open while it or the hour after it is the current one', function (string $now, bool $open) {
    expect(PeriodFunnelHours::isOpen(CarbonImmutable::parse('2026-11-18 10:00:00'), CarbonImmutable::parse($now)))->toBe($open);
})->with([
    'the hour itself' => ['2026-11-18 10:30:00', true],
    'the hour after, its last second' => ['2026-11-18 11:59:59', true],
    'two hours on' => ['2026-11-18 12:00:00', false],
    'a day on' => ['2026-11-19 10:30:00', false],
]);

it('counts an hour once it closed and ten minutes passed', function (string $now, string $last) {
    expect(PeriodFunnelHours::lastCountable(CarbonImmutable::parse($now))->toDateTimeString())->toBe($last);
})->with([
    'just before the 10:00 hour settles' => ['2026-11-18 12:09:59', '2026-11-18 09:00:00'],
    'as it settles' => ['2026-11-18 12:10:00', '2026-11-18 10:00:00'],
    'in another time zone' => ['2026-11-18 14:10:00+02:00', '2026-11-18 10:00:00'],
    'after midnight' => ['2026-11-19 01:00:00', '2026-11-18 22:00:00'],
]);
