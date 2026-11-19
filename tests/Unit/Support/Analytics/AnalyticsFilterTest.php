<?php

use App\Enums\AnalyticsDimension;
use App\Enums\TrafficDevice;
use App\Support\Analytics\AnalyticsFilter;
use App\Support\Analytics\AnalyticsPeriod;
use Carbon\CarbonImmutable;

/*
 * Which visits an Analytics figure counts: the period's days, read through a
 * wider span of arrivals; the device, the source and the campaign, together;
 * the path a breakdown opened — every column from AnalyticsDimension — and
 * the same over the window before, today alone's stopping its arrivals as
 * far into the day before as today has gone.
 */

function analyticsFilterPeriod(string $preset = '7d'): AnalyticsPeriod
{
    return AnalyticsPeriod::read($preset, null, null, CarbonImmutable::parse('2026-11-18 12:00:00', 'UTC'), 400, 93);
}

it('holds every condition at once, the path after the device, source and campaign', function () {
    $filter = (new AnalyticsFilter(analyticsFilterPeriod(), TrafficDevice::Mobile, 'reddit:organic', 'tag:launch'))
        ->within(AnalyticsDimension::Country, ['country' => 'US'])
        ->within(AnalyticsDimension::City, ['city' => 'Austin', 'country' => 'US'])
        ->within(AnalyticsDimension::Source, ['source_key' => 'google:organic']);

    expect($filter->conditions())->toBe([
        ['device', 'mobile'],
        ['source_key', 'reddit:organic'],
        ['campaign_key', 'tag:launch'],
        ['country', 'US'],
        ['city', 'Austin'],
        ['source_key', 'google:organic'],
    ])->and($filter->narrowsSource())->toBeTrue()
        ->and($filter->periodAndDevice()->conditions())->toBe([['device', 'mobile']])
        ->and($filter->periodAndDevice()->narrowsSource())->toBeFalse();
});

it('opens a group whose value is none as a column that is null', function () {
    $filter = (new AnalyticsFilter(analyticsFilterPeriod()))->within(AnalyticsDimension::Campaign, []);

    expect($filter->conditions())->toBe([['campaign_key', null]]);
});

it('reads the period’s network days through a wider span of arrivals', function () {
    $filter = new AnalyticsFilter(analyticsFilterPeriod());

    expect([$filter->fromDay(), $filter->toDay()])->toBe(['2026-11-12', '2026-11-18'])
        ->and($filter->arrivedFrom()->toDateTimeString())->toBe('2026-11-11 10:00:00')
        ->and($filter->arrivedBefore()->toDateTimeString())->toBe('2026-11-19 12:00:00');
});

it('carries every condition over to the window before', function () {
    $filter = new AnalyticsFilter(analyticsFilterPeriod(), TrafficDevice::Desktop, 'shared', null, ['game_id' => 4]);
    $previous = $filter->previous();

    expect([$previous?->fromDay(), $previous?->toDay()])->toBe(['2026-11-05', '2026-11-11'])
        ->and($previous?->conditions())->toBe($filter->conditions());
});

it('stops the arrivals of the window before today alone as far into the day before as today has gone', function () {
    $previous = (new AnalyticsFilter(analyticsFilterPeriod('today')))->previous();

    expect([$previous?->fromDay(), $previous?->toDay()])->toBe(['2026-11-17', '2026-11-17'])
        ->and($previous?->arrivedFrom()->toDateTimeString())->toBe('2026-11-16 10:00:00')
        ->and($previous?->arrivedBefore()->toDateTimeString())->toBe('2026-11-17 12:00:00')
        // The same day read as Yesterday, over and done, runs its whole span of arrivals.
        ->and((new AnalyticsFilter(analyticsFilterPeriod('yesterday')))->arrivedBefore()->toDateTimeString())->toBe('2026-11-18 12:00:00');
});

it('refuses a path on a column no dimension reads', function () {
    new AnalyticsFilter(analyticsFilterPeriod(), where: ['visitor_hash' => 'abc']);
})->throws(InvalidArgumentException::class, 'visitor_hash');
