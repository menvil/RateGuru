<?php

use App\Support\Traffic\Reports\ReportPeriod;
use Carbon\CarbonImmutable;

/**
 * A report page's period as its address names it: a preset of the page, or
 * a range of whole days that is read only when it can be — real dates, in
 * order, not past today and not starting before the oldest day kept. Anything
 * else is the default preset, never an error. Every page offers the same
 * presets, each the same days: all time from the first day there is data
 * for, never more days than the page reads.
 */
function readReportPeriod(mixed $preset, mixed $from = '', mixed $to = ''): ReportPeriod
{
    return ReportPeriod::read(ReportPeriod::PRESETS, '30d', $preset, $from, $to, CarbonImmutable::parse('2026-11-18 15:30:00', 'UTC'), 400);
}

/** @return array{0: string, 1: string} */
function reportPresetDays(string $preset, string $today = '2026-11-18 15:30:00', int $mostDays = 400, ?string $firstDay = null): array
{
    $days = ReportPeriod::presetDays($preset, CarbonImmutable::parse($today, 'UTC'), $mostDays, fn (): ?string => $firstDay);

    return [$days[0]->toDateString(), $days[1]->toDateString()];
}

it('offers the same presets on every report page, in order', function () {
    expect(ReportPeriod::PRESETS)->toBe([
        'today' => 'Today',
        'yesterday' => 'Yesterday',
        '7d' => 'Last 7 days',
        '30d' => 'Last 30 days',
        '90d' => 'Last 90 days',
        'this-month' => 'This month',
        'last-month' => 'Last month',
        'all' => 'All time',
    ]);
});

it('reads each preset as its days, today included', function (string $preset, array $days) {
    expect(reportPresetDays($preset))->toBe($days);
})->with([
    'today' => ['today', ['2026-11-18', '2026-11-18']],
    'yesterday' => ['yesterday', ['2026-11-17', '2026-11-17']],
    'last 7 days' => ['7d', ['2026-11-12', '2026-11-18']],
    'last 30 days' => ['30d', ['2026-10-20', '2026-11-18']],
    'last 90 days' => ['90d', ['2026-08-21', '2026-11-18']],
    'this month' => ['this-month', ['2026-11-01', '2026-11-18']],
    'last month' => ['last-month', ['2026-10-01', '2026-10-31']],
    'all time, no data yet: the most days' => ['all', ['2025-10-15', '2026-11-18']],
    'one it does not know: the last 30 days' => ['365d', ['2026-10-20', '2026-11-18']],
]);

it('reads this month on its first day as that day, and last month across a year and a short month', function () {
    expect(reportPresetDays('this-month', '2026-12-01 00:10:00'))->toBe(['2026-12-01', '2026-12-01'])
        ->and(reportPresetDays('yesterday', '2026-12-01 00:10:00'))->toBe(['2026-11-30', '2026-11-30'])
        ->and(reportPresetDays('last-month', '2027-01-15 08:00:00'))->toBe(['2026-12-01', '2026-12-31'])
        ->and(reportPresetDays('last-month', '2027-03-31 23:00:00'))->toBe(['2027-02-01', '2027-02-28']);
});

it('reads all time from the first day there is data for, never more days than the page reads, and asks for that day for all time alone', function () {
    $asked = 0;
    $first = function (?string $day) use (&$asked): Closure {
        return function () use (&$asked, $day): ?string {
            $asked++;

            return $day;
        };
    };
    $days = fn (string $preset, int $most, ?string $day): array => array_map(fn (CarbonImmutable $each): string => $each->toDateString(), ReportPeriod::presetDays($preset, CarbonImmutable::parse('2026-11-18 15:30:00', 'UTC'), $most, $first($day)));

    expect($days('all', 400, '2026-11-05'))->toBe(['2026-11-05', '2026-11-18'])
        ->and($days('all', 93, '2026-11-05'))->toBe(['2026-11-05', '2026-11-18'])
        // Data from further back than the page reads: its most days, up to today.
        ->and($days('all', 93, '2026-03-01'))->toBe(['2026-08-18', '2026-11-18'])
        ->and($days('all', 400, '2024-01-01'))->toBe(['2025-10-15', '2026-11-18'])
        // Data from today alone, or a day not yet come: today.
        ->and($days('all', 400, '2026-11-18'))->toBe(['2026-11-18', '2026-11-18'])
        ->and($days('all', 400, '2026-11-20'))->toBe(['2026-11-18', '2026-11-18'])
        // No data, or a day that isn't one: the most days.
        ->and($days('all', 30, null))->toBe(['2026-10-20', '2026-11-18'])
        ->and($days('all', 30, 'soon'))->toBe(['2026-10-20', '2026-11-18'])
        ->and($asked)->toBe(8);

    foreach (['today', 'yesterday', '7d', '30d', '90d', 'this-month', 'last-month', '365d'] as $preset) {
        $days($preset, 400, '2026-11-05');
    }

    expect($asked)->toBe(8);
});

it('reads a preset the page has, and the default for any other', function (mixed $preset, string $read) {
    expect(readReportPeriod($preset))->toMatchArray(['preset' => $read, 'fromDay' => null, 'toDay' => null])
        ->and(readReportPeriod($preset)->isCustom())->toBeFalse();
})->with([
    'a preset' => ['7d', '7d'],
    'today' => ['today', 'today'],
    'yesterday' => ['yesterday', 'yesterday'],
    'this month' => ['this-month', 'this-month'],
    'last month' => ['last-month', 'last-month'],
    'all time' => ['all', 'all'],
    'one the page lacks' => ['365d', '30d'],
    'nothing' => ['', '30d'],
    'not text' => [['7d'], '30d'],
    'a range’s name' => ['custom', '30d'],
]);

it('reads a range of whole days, up to today and back to the oldest day kept', function (string $from, string $to) {
    $period = readReportPeriod('7d', $from, $to);

    expect($period->isCustom())->toBeTrue()
        ->and([$period->preset, $period->fromDay, $period->toDay])->toBe([ReportPeriod::CUSTOM, $from, $to])
        ->and($period->start()?->toDateTimeString())->toBe($from.' 00:00:00')
        ->and($period->end()?->toDateTimeString())->toBe(CarbonImmutable::parse($to)->addDay()->toDateString().' 00:00:00');
})->with([
    'a fortnight' => ['2026-10-01', '2026-10-14'],
    'one day' => ['2026-11-02', '2026-11-02'],
    'ending today' => ['2026-11-01', '2026-11-18'],
    // 400 days with today: the oldest day still kept.
    'as long as visits are kept' => ['2025-10-15', '2026-11-18'],
]);

it('falls back to the preset for a range it cannot read', function (mixed $from, mixed $to) {
    expect(readReportPeriod('7d', $from, $to))->toMatchArray(['preset' => '7d', 'fromDay' => null, 'toDay' => null]);
})->with([
    'reversed' => ['2026-10-14', '2026-10-01'],
    'ending tomorrow' => ['2026-11-01', '2026-11-19'],
    'wholly ahead' => ['2026-12-01', '2026-12-05'],
    'a day longer than visits are kept' => ['2025-10-14', '2026-11-18'],
    'starting before the oldest day kept' => ['2024-01-01', '2024-01-10'],
    'no such day' => ['2026-02-30', '2026-03-02'],
    'no thirteenth month' => ['2026-13-01', '2026-13-02'],
    'a moment, not a day' => ['2026-10-01 00:00:00', '2026-10-02'],
    'loose digits' => ['2026-1-1', '2026-1-2'],
    'one end only' => ['2026-10-01', ''],
    'not text' => [['2026-10-01'], '2026-10-02'],
    'a number' => [20261001, 20261002],
    'words' => ['yesterday', 'today'],
]);

it('names two days as the picker and the figures show them', function () {
    $day = fn (string $date): CarbonImmutable => CarbonImmutable::parse($date, 'UTC');

    expect(ReportPeriod::span($day('2026-09-10'), $day('2026-10-09')))->toBe('10 Sep — 09 Oct 2026')
        ->and(ReportPeriod::span($day('2025-12-20'), $day('2026-01-05')))->toBe('20 Dec 2025 — 05 Jan 2026')
        ->and(ReportPeriod::span($day('2026-11-17'), $day('2026-11-17')))->toBe('17 Nov 2026');
});
