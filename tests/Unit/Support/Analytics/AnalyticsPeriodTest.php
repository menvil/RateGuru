<?php

use App\Support\Analytics\AnalyticsPeriod;
use App\Support\Traffic\Reports\ReportPeriod;
use Carbon\CarbonImmutable;

/*
 * The days Analytics reads, today included: a preset — all time from the
 * first visit's day, at most the last 93 days — or a range of whole days no
 * longer than 93 within the 400 days kept, anything else the preset named or
 * the default; the window before it — of the same length, this month's days
 * against the same days of last month, last month against the whole month
 * before, none for all time nor past the oldest day kept, today alone's only
 * as far into the day before as today has gone; how the comparison and the
 * days read; and the buckets of its chart — hours of one day, today's still
 * to come said so, days up to 31, then blocks of seven days from the first,
 * the last as long as the days left.
 */

function analyticsPeriod(mixed $preset, mixed $from = null, mixed $to = null, string $today = '2026-11-18 15:30:00', ?string $firstDay = null): AnalyticsPeriod
{
    return AnalyticsPeriod::read($preset, $from, $to, CarbonImmutable::parse($today, 'UTC'), 400, 93, fn (): ?string => $firstDay);
}

it('reads each preset as its days, today included', function (string $preset, string $from, string $to, int $days, string $label) {
    $period = analyticsPeriod($preset);

    expect([$period->preset, $period->fromDay, $period->toDay, $period->days(), $period->label()])->toBe([$preset, $from, $to, $days, $label])
        ->and($period->isCustom())->toBeFalse();
})->with([
    ['today', '2026-11-18', '2026-11-18', 1, 'Today'],
    ['yesterday', '2026-11-17', '2026-11-17', 1, 'Yesterday'],
    ['7d', '2026-11-12', '2026-11-18', 7, 'Last 7 days'],
    ['30d', '2026-10-20', '2026-11-18', 30, 'Last 30 days'],
    ['90d', '2026-08-21', '2026-11-18', 90, 'Last 90 days'],
    ['this-month', '2026-11-01', '2026-11-18', 18, 'This month'],
    ['last-month', '2026-10-01', '2026-10-31', 31, 'Last month'],
    // No visit yet: as many days as Analytics reads at a time.
    ['all', '2026-08-18', '2026-11-18', 93, 'All time'],
]);

it('offers every report page’s presets, in the same order', function () {
    expect(array_values(AnalyticsPeriod::PRESETS))->toBe(['Today', 'Yesterday', 'Last 7 days', 'Last 30 days', 'Last 90 days', 'This month', 'Last month', 'All time'])
        ->and(AnalyticsPeriod::PRESETS)->toBe(ReportPeriod::PRESETS);
});

it('reads all time from the first visit’s day, never longer than Analytics reads at a time, and looks that day up for all time alone', function () {
    $asked = 0;
    $read = function (string $preset, ?string $firstDay) use (&$asked): AnalyticsPeriod {
        return AnalyticsPeriod::read($preset, null, null, CarbonImmutable::parse('2026-11-18 15:30:00', 'UTC'), 400, 93, function () use (&$asked, $firstDay): ?string {
            $asked++;

            return $firstDay;
        });
    };
    $recent = $read('all', '2026-11-05');
    $older = $read('all', '2026-03-01');
    $todayOnly = $read('all', '2026-11-18');

    expect([$recent->fromDay, $recent->toDay, $recent->days(), $recent->range(), $recent->unit()])->toBe(['2026-11-05', '2026-11-18', 14, '05 Nov — 18 Nov 2026', AnalyticsPeriod::DAY])
        // Visits from further back: the last 93 days, the dates the picker shows saying so.
        ->and([$older->fromDay, $older->toDay, $older->days(), $older->range()])->toBe(['2026-08-18', '2026-11-18', 93, '18 Aug — 18 Nov 2026'])
        ->and([$todayOnly->fromDay, $todayOnly->toDay, $todayOnly->unit()])->toBe(['2026-11-18', '2026-11-18', AnalyticsPeriod::HOUR])
        ->and($asked)->toBe(3);

    foreach (['today', 'yesterday', '7d', '30d', '90d', 'this-month', 'last-month'] as $preset) {
        $read($preset, '2026-11-05');
    }

    // A range typed in needs no first day either.
    AnalyticsPeriod::read('all', '2026-11-01', '2026-11-10', CarbonImmutable::parse('2026-11-18 15:30:00', 'UTC'), 400, 93, function () use (&$asked): ?string {
        $asked++;

        return null;
    });

    expect($asked)->toBe(3);
});

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
        ->and([analyticsPeriod('today')->previous()?->fromDay, analyticsPeriod('today')->previous()?->toDay])->toBe(['2026-11-17', '2026-11-17'])
        ->and($atTheEdge->previous()?->fromDay)->toBe('2025-10-15')
        ->and($pastTheEdge->previous())->toBeNull();
});

it('compares this month with the same days of last month, last month with the whole month before, and all time with nothing', function () {
    $days = fn (?AnalyticsPeriod $period): ?array => $period === null ? null : [$period->fromDay, $period->toDay];

    // 1–18 November against 1–18 October; October against the whole of September.
    expect($days(analyticsPeriod('this-month')->previous()))->toBe(['2026-10-01', '2026-10-18'])
        ->and($days(analyticsPeriod('last-month')->previous()))->toBe(['2026-09-01', '2026-09-30'])
        // Last month shorter than this one has gone: its every day. A month shorter than the one before: the whole month before all the same.
        ->and($days(analyticsPeriod('this-month', today: '2027-03-31 10:00:00')->previous()))->toBe(['2027-02-01', '2027-02-28'])
        ->and($days(analyticsPeriod('last-month', today: '2027-03-10 10:00:00')->previous()))->toBe(['2027-01-01', '2027-01-31'])
        ->and($days(analyticsPeriod('last-month', today: '2027-01-15 10:00:00')->previous()))->toBe(['2026-11-01', '2026-11-30'])
        // Whole days: neither cuts the day before short.
        ->and(analyticsPeriod('this-month')->previous()?->arrivedBefore)->toBeNull()
        ->and(analyticsPeriod('last-month')->previous()?->arrivedBefore)->toBeNull()
        // Yesterday against the day before.
        ->and($days(analyticsPeriod('yesterday')->previous()))->toBe(['2026-11-16', '2026-11-16'])
        // All time has nothing earlier, whatever it covers.
        ->and(analyticsPeriod('all')->previous())->toBeNull()
        ->and(analyticsPeriod('all', firstDay: '2026-11-10')->previous())->toBeNull()
        ->and(analyticsPeriod('all', firstDay: '2026-11-18')->previous())->toBeNull()
        // A range of the same days as last month is a range: the days of the same length before it.
        ->and($days(analyticsPeriod('nope', '2026-10-01', '2026-10-31')->previous()))->toBe(['2026-08-31', '2026-09-30']);
});

it('compares today alone with the day before only as far into it as today has gone, and no other period', function () {
    $today = analyticsPeriod('today')->previous();

    expect($today?->arrivedBefore?->toDateTimeString())->toBe('2026-11-17 15:30:00')
        // Today typed in as a range, or this month on its first day, is today alone too.
        ->and(analyticsPeriod('nope', '2026-11-18', '2026-11-18')->previous()?->arrivedBefore?->toDateTimeString())->toBe('2026-11-17 15:30:00')
        ->and(analyticsPeriod('this-month', today: '2026-12-01 00:10:00')->previous()?->arrivedBefore?->toDateTimeString())->toBe('2026-11-30 00:10:00')
        ->and(analyticsPeriod('today')->arrivedBefore)->toBeNull()
        ->and(analyticsPeriod('yesterday')->previous()?->arrivedBefore)->toBeNull()
        ->and(analyticsPeriod('7d')->previous()?->arrivedBefore)->toBeNull()
        ->and(analyticsPeriod('nope', '2026-11-17', '2026-11-18')->previous()?->arrivedBefore)->toBeNull();
});

it('names the comparison and the days', function () {
    expect(analyticsPeriod('yesterday')->comparison())->toBe('the day before')
        ->and(analyticsPeriod('today')->comparison())->toBe('yesterday by this time')
        ->and(analyticsPeriod('today')->range())->toBe('18 Nov 2026')
        ->and(analyticsPeriod('today')->end()->toDateTimeString())->toBe('2026-11-19 00:00:00')
        ->and(analyticsPeriod('30d')->comparison())->toBe('the previous 30 days')
        ->and(analyticsPeriod('this-month')->comparison())->toBe('the same days of last month')
        ->and(analyticsPeriod('last-month')->comparison())->toBe('the month before')
        // This month on its first day is today alone.
        ->and(analyticsPeriod('this-month', today: '2026-12-01 00:10:00')->comparison())->toBe('yesterday by this time')
        ->and(analyticsPeriod('30d')->range())->toBe('20 Oct — 18 Nov 2026')
        ->and(analyticsPeriod('yesterday')->range())->toBe('17 Nov 2026')
        ->and(analyticsPeriod('last-month', today: '2027-01-05 00:00:00')->range())->toBe('01 Dec — 31 Dec 2026')
        ->and(analyticsPeriod('nope', '2025-12-15', '2026-01-13')->range())->toBe('15 Dec 2025 — 13 Jan 2026')
        ->and(analyticsPeriod('7d')->start()->toDateTimeString())->toBe('2026-11-12 00:00:00')
        ->and(analyticsPeriod('7d')->end()->toDateTimeString())->toBe('2026-11-19 00:00:00');
});

it('splits a single day into its hours, those of today not yet begun still to come', function () {
    $buckets = analyticsPeriod('yesterday')->buckets();
    $today = analyticsPeriod('today')->buckets();

    expect($buckets)->toHaveCount(24)
        ->and(array_column($buckets, 'label'))->toBe(array_map(fn (int $hour): string => sprintf('%02d:00', $hour), range(0, 23)))
        ->and($buckets[13]['from']->toDateTimeString())->toBe('2026-11-17 13:00:00')
        ->and($buckets[13]['before']->toDateTimeString())->toBe('2026-11-17 14:00:00')
        ->and($buckets[13]['hint'])->toBe('17 Nov 2026 · 13:00–14:00 UTC')
        ->and(array_unique(array_column($buckets, 'unit')))->toBe([AnalyticsPeriod::HOUR])
        ->and(array_unique(array_column($buckets, 'toCome')))->toBe([false])
        ->and($today)->toHaveCount(24)
        ->and($today[0]['from']->toDateTimeString())->toBe('2026-11-18 00:00:00')
        ->and($today[23]['before']->toDateTimeString())->toBe('2026-11-19 00:00:00')
        // At 15:30 the hour from 15:00 has begun; the ones after it haven't.
        ->and(array_keys(array_filter(array_column($today, 'toCome'))))->toBe(range(16, 23));
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
