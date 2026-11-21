<?php

use App\Support\Analytics\ArrivalWindow;
use Carbon\CarbonImmutable;

/*
 * A stretch of arrivals as its whole UTC days and the part days at its ends:
 * midnights are whole, a stretch that never ends has no end of whole days, a
 * stretch within a day — or two part days with no whole one between — is a
 * part day alone, and one of no time at all is nothing; whatever time zone
 * the moments come in.
 */

/** @return array{0: string|null, 1: string|null, 2: list<array{0: string, 1: string}>} */
function arrivalWindow(string $since, ?string $until): array
{
    $window = ArrivalWindow::of(CarbonImmutable::parse($since), $until === null ? null : CarbonImmutable::parse($until));

    return [$window->fromDay, $window->beforeDay, array_map(fn (array $part): array => [$part[0]->toDateTimeString(), $part[1]->toDateTimeString()], $window->parts)];
}

it('splits a stretch into whole days and part days', function (string $since, ?string $until, ?string $fromDay, ?string $beforeDay, array $parts) {
    expect(arrivalWindow($since, $until))->toBe([$fromDay, $beforeDay, $parts]);
})->with([
    'whole days only' => ['2026-10-20 00:00:00', '2026-11-19 00:00:00', '2026-10-20', '2026-11-19', []],
    'up to now' => ['2026-10-20 00:00:00', '2026-11-18 14:05:01', '2026-10-20', '2026-11-18', [['2026-11-18 00:00:00', '2026-11-18 14:05:01']]],
    'from a moment and up to now' => ['2026-09-20 09:54:59', '2026-10-20 00:00:00', '2026-09-21', '2026-10-20', [['2026-09-20 09:54:59', '2026-09-21 00:00:00']]],
    'part days at both ends' => ['2026-10-20 13:37:11', '2026-11-18 09:12:45', '2026-10-21', '2026-11-18', [['2026-10-20 13:37:11', '2026-10-21 00:00:00'], ['2026-11-18 00:00:00', '2026-11-18 09:12:45']]],
    'for ever after a midnight' => ['2026-07-20 00:00:00', null, '2026-07-20', null, []],
    'for ever after a moment' => ['2026-07-20 08:00:00', null, '2026-07-21', null, [['2026-07-20 08:00:00', '2026-07-21 00:00:00']]],
    'within one day' => ['2026-11-18 00:00:00', '2026-11-18 14:05:01', null, null, [['2026-11-18 00:00:00', '2026-11-18 14:05:01']]],
    'two part days, no whole one' => ['2026-11-17 13:00:00', '2026-11-18 14:05:01', null, null, [['2026-11-17 13:00:00', '2026-11-18 14:05:01']]],
    'one whole day between two parts' => ['2026-11-16 13:00:00', '2026-11-18 01:00:00', '2026-11-17', '2026-11-18', [['2026-11-16 13:00:00', '2026-11-17 00:00:00'], ['2026-11-18 00:00:00', '2026-11-18 01:00:00']]],
    'a single whole day' => ['2026-11-17 00:00:00', '2026-11-18 00:00:00', '2026-11-17', '2026-11-18', []],
    'no time at all' => ['2026-11-18 10:00:00', '2026-11-18 10:00:00', null, null, []],
    'no time at all, at midnight' => ['2026-11-18 00:00:00', '2026-11-18 00:00:00', null, null, []],
    'another time zone' => ['2026-10-20 03:00:00+03:00', '2026-11-19 00:00:00+00:00', '2026-10-20', '2026-11-19', []],
]);

it('says whether a stretch holds a whole day', function () {
    expect(ArrivalWindow::of(CarbonImmutable::parse('2026-11-17 00:00:00'), CarbonImmutable::parse('2026-11-18 00:00:00'))->hasWholeDays())->toBeTrue()
        ->and(ArrivalWindow::of(CarbonImmutable::parse('2026-11-17 13:00:00'), CarbonImmutable::parse('2026-11-18 14:00:00'))->hasWholeDays())->toBeFalse();
});
