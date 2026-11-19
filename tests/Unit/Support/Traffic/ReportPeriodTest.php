<?php

use App\Support\Traffic\Reports\ReportPeriod;
use Carbon\CarbonImmutable;

/**
 * A report page's period as its address names it: a preset of the page, or
 * a range of whole days that is read only when it can be — real dates, in
 * order, not past today and not starting before the oldest day kept. Anything
 * else is the default preset, never an error.
 */
const REPORT_PERIOD_PRESETS = ['yesterday' => 'Yesterday', '7d' => 'Last 7 days', '30d' => 'Last 30 days', '90d' => 'Last 90 days'];

function readReportPeriod(mixed $preset, mixed $from = '', mixed $to = ''): ReportPeriod
{
    return ReportPeriod::read(REPORT_PERIOD_PRESETS, '30d', $preset, $from, $to, CarbonImmutable::parse('2026-11-18 15:30:00', 'UTC'), 400);
}

it('reads a preset the page has, and the default for any other', function (mixed $preset, string $read) {
    expect(readReportPeriod($preset))->toMatchArray(['preset' => $read, 'fromDay' => null, 'toDay' => null])
        ->and(readReportPeriod($preset)->isCustom())->toBeFalse();
})->with([
    'a preset' => ['7d', '7d'],
    'yesterday' => ['yesterday', 'yesterday'],
    'one the page lacks' => ['all', '30d'],
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
