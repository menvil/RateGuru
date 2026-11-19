<?php

use App\Support\Analytics\AnalyticsPeriod;
use Carbon\CarbonImmutable;

/*
 * The days Analytics reads, today included: a preset, or a range of whole
 * days no longer than 93 within the 400 days kept — anything else the preset
 * named or the default; the window of the same length before it, none past
 * the oldest day kept; how the comparison and the days read; and the buckets
 * of its chart — hours of one day, days up to 31, then blocks of seven days
 * from the first, the last as long as the days left.
 */

function analyticsPeriod(mixed $preset, mixed $from = null, mixed $to = null, string $today = '2026-11-18 15:30:00'): AnalyticsPeriod
{
    return AnalyticsPeriod::read($preset, $from, $to, CarbonImmutable::parse($today, 'UTC'), 400, 93);
}

it('reads each preset as its days, today included', function (string $preset, string $from, string $to, int $days, string $label) {
    $period = analyticsPeriod($preset);

    expect([$period->preset, $period->fromDay, $period->toDay, $period->days(), $period->label()])->toBe([$preset, $from, $to, $days, $label])
        ->and($period->isCustom())->toBeFalse();
})->with([
    ['yesterday', '2026-11-17', '2026-11-17', 1, 'Yesterday'],
    ['7d', '2026-11-12', '2026-11-18', 7, 'Last 7 days'],
    ['30d', '2026-10-20', '2026-11-18', 30, 'Last 30 days'],
    ['90d', '2026-08-21', '2026-11-18', 90, 'Last 90 days'],
    ['this-month', '2026-11-01', '2026-11-18', 18, 'This month'],
    ['last-month', '2026-10-01', '2026-10-31', 31, 'Last month'],
]);

it('reads this month on its first day as that day alone, and last month across a year and a short month', function () {
    $first = analyticsPeriod('this-month', today: '2026-12-01 00:10:00');
    $january = analyticsPeriod('last-month', today: '2027-01-15 08:00:00');
    $march = analyticsPeriod('last-month', today: '2027-03-31 23:00:00');

    expect([$first->fromDay, $first->toDay, $first->unit()])->toBe(['2026-12-01', '2026-12-01', AnalyticsPeriod::HOUR])
        ->and([$january->fromDay, $january->toDay])->toBe(['2026-12-01', '2026-12-31'])
        ->and([$march->fromDay, $march->toDay])->toBe(['2027-02-01', '2027-02-28']);
});

it('reads a range of up to 93 days, and a longer one like any range it cannot read', function () {
    $longest = analyticsPeriod('30d', '2026-08-18', '2026-11-18');
    $tooLong = analyticsPeriod('30d', '2026-08-17', '2026-11-18');
    $tooLongWithPreset = analyticsPeriod('this-month', '2026-08-17', '2026-11-18');

    expect([$longest->preset, $longest->days(), $longest->label()])->toBe(['custom', 93, 'Custom range'])
        ->and([$tooLong->preset, $tooLong->fromDay, $tooLong->toDay])->toBe(['30d', '2026-10-20', '2026-11-18'])
        ->and($tooLongWithPreset->preset)->toBe('this-month');
});

it('falls back to the default for an unknown preset and a range it cannot read', function (mixed $preset, mixed $from, mixed $to) {
    expect(analyticsPeriod($preset, $from, $to)->preset)->toBe(AnalyticsPeriod::DEFAULT);
})->with([
    'unknown preset' => ['365d', null, null],
    'no preset at all' => [null, null, null],
    'a forged array' => [['30d'], null, null],
    'ends after today' => ['nope', '2026-11-10', '2026-11-19'],
    'first after last' => ['nope', '2026-11-12', '2026-11-10'],
    'before the oldest day kept' => ['nope', '2025-10-13', '2025-10-20'],
    'not a date' => ['nope', '2026-02-30', '2026-03-02'],
]);

it('compares with the window of the same length just before it, none once that is no longer kept', function () {
    $month = analyticsPeriod('30d');
    $previous = $month->previous();
    // The oldest day kept is 2025-10-15, today among the 400.
    $atTheEdge = analyticsPeriod('nope', '2025-10-25', '2025-11-03');
    $pastTheEdge = analyticsPeriod('nope', '2025-10-24', '2025-11-02');

    expect([$previous?->fromDay, $previous?->toDay, $previous?->days()])->toBe(['2026-09-20', '2026-10-19', 30])
        ->and(analyticsPeriod('yesterday')->previous()?->fromDay)->toBe('2026-11-16')
        ->and(analyticsPeriod('last-month')->previous()?->fromDay)->toBe('2026-08-31')
        ->and($atTheEdge->previous()?->fromDay)->toBe('2025-10-15')
        ->and($pastTheEdge->previous())->toBeNull();
});

it('names the comparison and the days', function () {
    expect(analyticsPeriod('yesterday')->comparison())->toBe('the day before')
        ->and(analyticsPeriod('30d')->comparison())->toBe('the previous 30 days')
        ->and(analyticsPeriod('30d')->range())->toBe('20 Oct — 18 Nov 2026')
        ->and(analyticsPeriod('yesterday')->range())->toBe('17 Nov 2026')
        ->and(analyticsPeriod('last-month', today: '2027-01-05 00:00:00')->range())->toBe('01 Dec — 31 Dec 2026')
        ->and(analyticsPeriod('nope', '2025-12-15', '2026-01-13')->range())->toBe('15 Dec 2025 — 13 Jan 2026')
        ->and(analyticsPeriod('7d')->start()->toDateTimeString())->toBe('2026-11-12 00:00:00')
        ->and(analyticsPeriod('7d')->end()->toDateTimeString())->toBe('2026-11-19 00:00:00');
});

it('splits a single day into its hours', function () {
    $buckets = analyticsPeriod('yesterday')->buckets();

    expect($buckets)->toHaveCount(24)
        ->and(array_column($buckets, 'label'))->toBe(array_map(fn (int $hour): string => sprintf('%02d:00', $hour), range(0, 23)))
        ->and($buckets[13]['from']->toDateTimeString())->toBe('2026-11-17 13:00:00')
        ->and($buckets[13]['before']->toDateTimeString())->toBe('2026-11-17 14:00:00')
        ->and($buckets[13]['hint'])->toBe('17 Nov 2026 · 13:00–14:00 UTC')
        ->and(array_unique(array_column($buckets, 'unit')))->toBe([AnalyticsPeriod::HOUR]);
});

it('splits up to 31 days into days', function () {
    $buckets = analyticsPeriod('nope', '2026-10-19', '2026-11-18')->buckets();

    expect($buckets)->toHaveCount(31)
        ->and($buckets[0])->toMatchArray(['unit' => AnalyticsPeriod::DAY, 'label' => '19 Oct', 'hint' => 'Mon 19 Oct 2026', 'fromDay' => '2026-10-19', 'toDay' => '2026-10-19', 'days' => 1])
        ->and($buckets[30]['label'])->toBe('18 Nov')
        ->and($buckets[30]['before']->toDateTimeString())->toBe('2026-11-19 00:00:00');
});

it('splits a longer period into blocks of seven days from its first, the last as long as the days left', function () {
    $quarter = analyticsPeriod('90d')->buckets();
    $longerThanAMonth = analyticsPeriod('nope', '2026-10-18', '2026-11-18')->buckets();

    expect($quarter)->toHaveCount(13)
        ->and($quarter[0])->toMatchArray(['unit' => AnalyticsPeriod::WEEK, 'label' => 'Week of 21 Aug', 'hint' => '21 Aug — 27 Aug 2026', 'fromDay' => '2026-08-21', 'toDay' => '2026-08-27', 'days' => 7])
        ->and($quarter[12])->toMatchArray(['label' => 'Week of 13 Nov', 'hint' => '13 Nov — 18 Nov 2026 · 6 days', 'fromDay' => '2026-11-13', 'toDay' => '2026-11-18', 'days' => 6])
        ->and($quarter[12]['before']->toDateTimeString())->toBe('2026-11-19 00:00:00')
        ->and(array_sum(array_column($quarter, 'days')))->toBe(90)
        ->and($longerThanAMonth)->toHaveCount(5)
        ->and($longerThanAMonth[4])->toMatchArray(['fromDay' => '2026-11-15', 'toDay' => '2026-11-18', 'days' => 4])
        ->and(analyticsPeriod('nope', '2026-11-12', '2026-11-12')->unit())->toBe(AnalyticsPeriod::HOUR);
});

it('names the days of a short last block, down to a single one', function (string $from, string $to, int $blocks, string $hint, int $days) {
    $buckets = analyticsPeriod('nope', $from, $to)->buckets();

    expect($buckets)->toHaveCount($blocks)
        ->and($buckets[$blocks - 1])->toMatchArray(['hint' => $hint, 'days' => $days]);
})->with([
    ['2026-10-11', '2026-11-12', 5, '08 Nov — 12 Nov 2026 · 5 days', 5],
    ['2026-09-23', '2026-10-29', 6, '28 Oct — 29 Oct 2026 · 2 days', 2],
    ['2026-09-23', '2026-10-28', 6, '28 Oct 2026 · 1 day', 1],
    ['2026-09-23', '2026-11-03', 6, '28 Oct — 03 Nov 2026', 7],
]);
