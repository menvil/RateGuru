<?php

use App\Actions\Analytics\RefreshAnalyticsDayAction;
use App\Enums\AnalyticsDimension;
use App\Enums\TrafficDevice;
use App\Models\AnalyticsCell;
use App\Models\AnalyticsCellAccount;
use App\Models\AnalyticsPid;
use App\Models\AnalyticsRollupState;
use App\Models\TrafficSource;
use App\Models\TrafficVisit;
use App\Queries\Analytics\AnalyticsRollupGate;
use App\Queries\Analytics\AnalyticsVisitsQuery;
use App\Support\Analytics\AnalyticsCellLevel;
use App\Support\Analytics\AnalyticsSource;
use App\Support\Traffic\Capture\BotSignals;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
 * The cells of each day's people (analytics_cells): written with each day's
 * rows where they are kept — PostgreSQL with the roaringbitmap extension —
 * each browser one number however many days it came on; read by any figure,
 * split or list that fits a level, over whole days; and the same figures as
 * the rows give, every one of them, for every filter, split, way of counting
 * the visits of no value, group kept and list asked for. Elsewhere none are
 * written and every read takes the rows.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-18 12:00:00'));

    if (! app(AnalyticsRollupGate::class)->cellsKept()) {
        $this->markTestSkipped('The cells are kept only on PostgreSQL with the roaringbitmap extension.');
    }
});

/**
 * Three days of people: two sources and none, two campaigns and none, both
 * devices, two countries and none; people on two days and in two sources;
 * new and known ones; every step, actions and spend; accounts signed up long
 * enough ago to have had their days to activate and recently.
 */
function cellsFixture(): void
{
    $paid = 'paid:7';
    $people = [
        ['ann', '2026-11-12', ['source_key' => $paid, 'campaign_key' => 'paid:3', 'device' => 'mobile', 'country' => 'US', 'started_at' => '2026-11-12 10:01:00', 'completed_at' => '2026-11-12 10:03:00']],
        ['ann', '2026-11-15', ['source_key' => AnalyticsSource::DIRECT, 'device' => 'mobile', 'country' => 'US', 'votes' => 2]],
        ['bob', '2026-11-12', ['source_key' => $paid, 'campaign_key' => 'paid:3', 'device' => 'desktop', 'country' => 'DE', 'signup_at' => '2026-11-01 10:00:00', 'account_at' => '2026-11-01 10:00:00', 'activated_at' => '2026-11-02 09:00:00', 'spend' => '0.25']],
        ['cat', '2026-11-15', ['source_key' => $paid, 'campaign_key' => 'tag:spring', 'device' => 'mobile', 'country' => null, 'signup_at' => '2026-11-15 10:05:00', 'account_at' => '2026-11-15 10:06:00', 'spend' => '0.10', 'spend_estimated' => true]],
        ['dan', '2026-11-16', ['source_key' => null, 'device' => 'desktop', 'country' => 'DE', 'known' => true, 'comments' => 1]],
        ['eve', '2026-11-16', ['source_key' => AnalyticsSource::DIRECT, 'device' => 'mobile', 'country' => 'US', 'utm_source' => 'newsletter']],
        ['fay', '2026-11-16', ['source_key' => $paid, 'campaign_key' => 'tag:spring', 'device' => 'desktop', 'country' => 'US', 'opened_at' => null]],
        ['gus', '2026-11-16', ['source_key' => $paid, 'campaign_key' => 'paid:3', 'device' => 'mobile', 'country' => 'DE', 'goal_at' => '2026-11-16 11:00:00', 'signup_form_at' => '2026-11-16 11:01:00']],
    ];

    foreach ($people as [$browser, $day, $attributes]) {
        analyticsVisit($browser, ['network_day' => $day, 'arrived_at' => $day.' 10:00:00', ...$attributes]);
    }
}

it('writes the cells of each day at every level, with one number a browser however many days it came on', function () {
    cellsFixture();
    fillAnalyticsRollup();

    expect(app(AnalyticsRollupGate::class)->readsCells())->toBeTrue()
        ->and(AnalyticsCell::query()->distinct()->orderBy('level')->pluck('level')->all())->toBe(collect(AnalyticsCellLevel::cases())->map->value->sort()->values()->all())
        ->and(AnalyticsCell::query()->distinct()->orderBy('network_day')->pluck('network_day')->all())->toBe(['2026-11-12', '2026-11-15', '2026-11-16'])
        // Six browsers whose page ran — fay's never did — ann's on two days.
        ->and(AnalyticsPid::query()->count())->toBe(6)
        ->and(AnalyticsPid::query()->distinct()->count('pid'))->toBe(6)
        // bob and cat signed up, each in the cells of their own visit's day.
        ->and(AnalyticsCellAccount::query()->where('level', 'device')->orderBy('signup_day')->pluck('signup_day')->all())->toBe(['2026-11-01', '2026-11-15'])
        ->and(AnalyticsRollupState::current()->cells_filled_at)->not->toBeNull();
});

it('gives every figure the rows give, whatever the filter', function (array $filter) {
    cellsFixture();

    $figures = readBothWays(AnalyticsVisitsQuery::class)->figures(analyticsFilter('7d', ...$filter));

    expect($figures->visitors)->toBeGreaterThanOrEqual(0);
})->with([
    'every visit' => [[]],
    'one device' => [[TrafficDevice::Mobile]],
    'one source' => [[null, 'paid:7']],
    'one campaign, any source' => [[null, null, 'paid:3']],
    'a source, a campaign and a device' => [[TrafficDevice::Mobile, 'paid:7', 'tag:spring']],
    'the visits of no source' => [[TrafficDevice::Desktop, AnalyticsSource::DIRECT]],
]);

it('gives every split the rows give, whatever the visits of no value are counted as and the groups kept', function (AnalyticsDimension $dimension, string $unnamed, ?int $limit, array $keep) {
    cellsFixture();

    $split = readBothWays(AnalyticsVisitsQuery::class)->by(analyticsFilter('7d'), $dimension, $limit, 1, $unnamed, $keep);

    expect($split['rows'])->toBeArray();
})->with([
    'sources, the unnamed listed' => [AnalyticsDimension::Source, AnalyticsVisitsQuery::UNNAMED_LISTED, null, []],
    'sources, one listed and the rest' => [AnalyticsDimension::Source, AnalyticsVisitsQuery::UNNAMED_LISTED, 1, []],
    'sources, the unnamed in the rest' => [AnalyticsDimension::Source, AnalyticsVisitsQuery::UNNAMED_IN_REST, 1, []],
    'countries, the unnamed apart' => [AnalyticsDimension::Country, AnalyticsVisitsQuery::UNNAMED_APART, 1, []],
    'campaigns, the unnamed left out' => [AnalyticsDimension::Campaign, AnalyticsVisitsQuery::UNNAMED_LEFT_OUT, 1, []],
    'campaigns, one kept past the limit' => [AnalyticsDimension::Campaign, AnalyticsVisitsQuery::UNNAMED_LISTED, 1, [['campaign_key' => 'tag:spring']]],
    'devices' => [AnalyticsDimension::Device, AnalyticsVisitsQuery::UNNAMED_LISTED, null, []],
]);

it('splits a filtered period by a column of the same level, and the matured accounts of each group with it', function () {
    cellsFixture();

    $split = readBothWays(AnalyticsVisitsQuery::class)->by(analyticsFilter('30d', TrafficDevice::Desktop, 'paid:7'), AnalyticsDimension::Campaign);

    expect(collect($split['rows'])->map(fn (array $row): array => [$row['keys']['campaign_key'], $row['figures']->accounts, $row['figures']->maturedAccounts])->all())
        ->toBe([['paid:3', 1, 1]]);
});

it('offers the values a filter lists the way the rows do, the most visits first', function () {
    cellsFixture();

    expect(readBothWays(AnalyticsVisitsQuery::class)->top(analyticsFilter('7d'), AnalyticsDimension::Source, 30))->toHaveCount(3)
        ->and(readBothWays(AnalyticsVisitsQuery::class)->top(analyticsFilter('7d', null, 'paid:7'), AnalyticsDimension::Campaign, 100, 'tag:'))->toBe([['campaign_key' => 'tag:spring']]);
});

it('gives the people of each day and block of days, of values asked for, of groups among values and of pairs the rows give', function () {
    cellsFixture();
    $week = analyticsFilter('7d');
    $quarter = analyticsFilter('90d', TrafficDevice::Mobile);

    expect(readBothWays(AnalyticsVisitsQuery::class)->series($week, $week->period->buckets()))->toHaveCount(7)
        ->and(readBothWays(AnalyticsVisitsQuery::class)->series($quarter, $quarter->period->buckets()))->not->toBe([])
        ->and(readBothWays(AnalyticsVisitsQuery::class)->of($week, AnalyticsDimension::Source, ['paid:7', AnalyticsSource::DIRECT, 'paid:404']))->toHaveKeys(['paid:7', AnalyticsSource::DIRECT])
        ->and(readBothWays(AnalyticsVisitsQuery::class)->of(analyticsFilter('30d'), AnalyticsDimension::Campaign, ['paid:3'])['paid:3']['figures']->maturedAccounts)->toBe(1)
        ->and(readBothWays(AnalyticsVisitsQuery::class)->among($week, AnalyticsDimension::Campaign, ['paid:3', 'tag:spring']))->toHaveCount(2)
        ->and(readBothWays(AnalyticsVisitsQuery::class)->among($week, AnalyticsDimension::Campaign, ['paid:3', 'tag:spring'], [['campaign_key' => 'tag:spring']]))->toHaveCount(1)
        ->and(readBothWays(AnalyticsVisitsQuery::class)->pairs($week, AnalyticsDimension::Campaign, AnalyticsDimension::Source, ['paid:3', 'tag:spring']))->toHaveCount(2);
});

it('reads the cells, not the rows, for every read that fits a level', function () {
    cellsFixture();
    fillAnalyticsRollup();
    $week = analyticsFilter('7d');
    $query = app(AnalyticsVisitsQuery::class);
    $reads = [
        'figures' => fn () => $query->figures($week),
        'by' => fn () => $query->by($week, AnalyticsDimension::Campaign, 1),
        'series' => fn () => $query->series($week, $week->period->buckets()),
        'of' => fn () => $query->of($week, AnalyticsDimension::Country, ['US']),
        'among' => fn () => $query->among($week, AnalyticsDimension::Campaign, ['paid:3']),
        'pairs' => fn () => $query->pairs($week, AnalyticsDimension::Campaign, AnalyticsDimension::Source, ['paid:3']),
        'top' => fn () => $query->top($week, AnalyticsDimension::Device, 10),
        'returning' => fn () => $query->returning($week),
        'quality' => fn () => $query->quality($week),
    ];

    foreach ($reads as $read => $call) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $call();
        $tables = collect(DB::getQueryLog())->pluck('query')->implode(' ');
        DB::disableQueryLog();

        expect(str_contains($tables, 'analytics_cells'))->toBeTrue("{$read}() reads the cells")
            ->and(str_contains($tables, 'analytics_visits'))->toBeFalse("{$read}() reads no row of the visits");
    }

    // A column no level keeps — the landing — reads the rows.
    DB::flushQueryLog();
    DB::enableQueryLog();
    $query->by($week, AnalyticsDimension::Landing);

    expect(collect(DB::getQueryLog())->pluck('query')->implode(' '))->toContain('analytics_visits');
    DB::disableQueryLog();
});

it('counts the people who came back the way the rows do: on two days of the period, or seen on a day before it, whatever the filter', function () {
    cellsFixture();
    // hal came before the week, on a day the filter leaves out; ivy only before it.
    analyticsVisit('hal', ['network_day' => '2026-11-02', 'arrived_at' => '2026-11-02 10:00:00', 'source_key' => AnalyticsSource::DIRECT, 'device' => 'desktop']);
    analyticsVisit('hal', ['network_day' => '2026-11-16', 'arrived_at' => '2026-11-16 10:00:00', 'source_key' => 'paid:7', 'device' => 'mobile']);
    analyticsVisit('ivy', ['network_day' => '2026-11-03', 'arrived_at' => '2026-11-03 10:00:00', 'device' => 'mobile']);

    // ann on two days of the week, hal seen before it.
    expect(readBothWays(AnalyticsVisitsQuery::class)->returning(analyticsFilter('7d')))->toBe(2)
        ->and(readBothWays(AnalyticsVisitsQuery::class)->returning(analyticsFilter('7d', TrafficDevice::Mobile, 'paid:7')))->toBe(1)
        ->and(readBothWays(AnalyticsVisitsQuery::class)->returning(analyticsFilter('7d', TrafficDevice::Desktop)))->toBe(0);
});

it('gives what the figures leave out the way the rows do: Direct, every paid click, those of crawlers and those whose page never ran, and their cost', function () {
    cellsFixture();
    $source = TrafficSource::factory()->create();
    // A crawler's paid click on a day of its own, one whose page never ran on a day with people, and one that ran.
    analyticsVisit('bot', ['network_day' => '2026-11-14', 'arrived_at' => '2026-11-14 10:00:00', 'source_id' => $source->id, 'source_key' => 'paid:7', 'device' => 'desktop', 'opened_at' => null, 'bot_flags' => BotSignals::CRAWLER, 'spend' => '0.05']);
    analyticsVisit('jay', ['network_day' => '2026-11-16', 'arrived_at' => '2026-11-16 10:00:00', 'source_id' => $source->id, 'source_key' => 'paid:7', 'device' => 'mobile', 'opened_at' => null, 'spend' => '0.02', 'spend_estimated' => true]);
    analyticsVisit('kim', ['network_day' => '2026-11-16', 'arrived_at' => '2026-11-16 10:00:00', 'source_id' => $source->id, 'source_key' => 'paid:7', 'device' => 'mobile', 'spend' => '0.03']);

    $quality = readBothWays(AnalyticsVisitsQuery::class)->quality(analyticsFilter('7d'));

    expect($quality)->toMatchArray(['paidClicks' => 3, 'crawlers' => 1, 'neverRan' => 1, 'spend' => '0.10000000', 'spendEstimated' => '0.02000000'])
        ->and(readBothWays(AnalyticsVisitsQuery::class)->quality(analyticsFilter('7d', TrafficDevice::Desktop, 'paid:7')))->toBeArray()
        ->and(readBothWays(AnalyticsVisitsQuery::class)->quality(analyticsFilter('7d', null, AnalyticsSource::DIRECT)))->toBeArray()
        // A day only a crawler came on lists no one, and no split lists its cell.
        ->and(readBothWays(AnalyticsVisitsQuery::class)->series(analyticsFilter('7d'), analyticsFilter('7d')->period->buckets()))->toHaveCount(7)
        ->and(readBothWays(AnalyticsVisitsQuery::class)->by(analyticsFilter('7d', TrafficDevice::Desktop), AnalyticsDimension::Source))->toBeArray()
        ->and(readBothWays(AnalyticsVisitsQuery::class)->returning(analyticsFilter('7d', TrafficDevice::Desktop)))->toBe(0);
});

it('writes a day’s cells again when one of its visits changes', function () {
    cellsFixture();
    fillAnalyticsRollup();

    $visit = TrafficVisit::query()->where('visitor_hash', hash('sha256', 'gus'))->firstOrFail();
    $visit->forceFill(['signup_at' => '2026-11-16 12:00:00', 'account_at' => '2026-11-16 12:00:00'])->save();
    app(RefreshAnalyticsDayAction::class)->handle('2026-11-16');
    app()->forgetScopedInstances();

    // bob and cat before, gus now.
    expect(app(AnalyticsRollupGate::class)->readsCells())->toBeTrue()
        ->and(app(AnalyticsVisitsQuery::class)->figures(analyticsFilter('7d'))->accounts)->toBe(3);
});

it('reads the rows, never the cells, once the setting turns them off or before they are filled', function () {
    cellsFixture();
    fillAnalyticsRollup();

    config(['analytics.rollup.cells' => false]);
    app()->forgetScopedInstances();

    expect(app(AnalyticsRollupGate::class)->readsCells())->toBeFalse();

    config(['analytics.rollup.cells' => true]);
    AnalyticsRollupState::query()->whereKey(AnalyticsRollupState::ID)->update(['cells_filled_at' => null]);
    app()->forgetScopedInstances();

    expect(app(AnalyticsRollupGate::class)->readsCells())->toBeFalse();
});
