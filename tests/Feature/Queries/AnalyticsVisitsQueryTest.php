<?php

use App\Enums\AnalyticsDimension;
use App\Enums\GameAudience;
use App\Enums\GameEventType;
use App\Enums\TrafficChannel;
use App\Enums\TrafficDevice;
use App\Models\GameAttempt;
use App\Models\GameEvent;
use App\Models\GameVersion;
use App\Models\TrafficSource;
use App\Models\TrafficVisit;
use App\Queries\Analytics\AnalyticsVisitsQuery;
use App\Support\Analytics\AnalyticsSource;
use App\Support\Traffic\Capture\BotSignals;
use Carbon\CarbonImmutable;

/*
 * The people of Growth → Analytics, read straight from the visits: each
 * browser once however many visits and days it came on, and only once its
 * page ran; a known browser never a new one; a person in each source they
 * came through and once in all; a source and a campaign narrowing together;
 * the people past the top groups counted together, each once; the people of
 * each hour, day and block of days; those who came back; what the people
 * leave out; the attempts and result shares of the visits; and what the
 * page's filters offer, whether a filter admits anyone, and when the first
 * visit was recorded. The same on every database engine.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-18 12:00:00'));
});

function analyticsQuery(): AnalyticsVisitsQuery
{
    return app(AnalyticsVisitsQuery::class);
}

it('counts each browser once, however many visits and days it came on, and only once its page ran', function () {
    analyticsVisit('ann', ['network_day' => '2026-11-12']);
    analyticsVisit('ann', ['network_day' => '2026-11-15']);
    analyticsVisit('ann', ['network_day' => '2026-11-18', 'arrived_at' => '2026-11-18 09:00:00']);
    analyticsVisit('bob');
    analyticsVisit('cat', ['opened_at' => null]);
    // Its network day is outside the period, whatever its arrival.
    analyticsVisit('dan', ['network_day' => '2026-11-11', 'arrived_at' => '2026-11-12 02:00:00']);
    analyticsVisit('eve', ['network_day' => '2026-11-12', 'arrived_at' => '2026-11-11 21:00:00']);

    $figures = analyticsQuery()->figures(analyticsFilter());

    expect($figures->visitors)->toBe(3)
        ->and($figures->newVisitors)->toBe(3)
        ->and($figures->isEmpty())->toBeFalse()
        ->and(analyticsQuery()->figures(analyticsFilter('yesterday'))->isEmpty())->toBeTrue();
});

it('counts a known browser among the visitors but never among the new ones the accounts are a share of', function () {
    analyticsVisit('member', ['known' => true]);
    analyticsVisit('member', ['known' => true, 'network_day' => '2026-11-16']);
    analyticsVisit('newcomer', ['account_at' => '2026-11-15 10:20:00', 'signup_at' => '2026-11-15 10:10:00']);
    analyticsVisit('stranger');

    $figures = analyticsQuery()->figures(analyticsFilter());

    expect([$figures->visitors, $figures->newVisitors, $figures->accounts])->toBe([3, 2, 1])
        ->and($figures->accountRate())->toBe(0.5);
});

it('reads how far people got, what they did and what they cost', function () {
    $source = TrafficSource::factory()->create();
    // Signed up 1 November: its seven days to activate are over by today; signed up 15 November: not yet.
    analyticsVisit('early', ['network_day' => '2026-11-01', 'started_at' => '2026-11-01 10:01:00', 'completed_at' => '2026-11-01 10:03:00', 'goal_at' => '2026-11-01 10:03:00',
        'signup_form_at' => '2026-11-01 10:04:00', 'signup_at' => '2026-11-01 10:05:00', 'account_at' => '2026-11-01 10:06:00', 'activated_at' => '2026-11-03 09:00:00']);
    analyticsVisit('late', ['started_at' => '2026-11-15 10:01:00', 'signup_form_at' => '2026-11-15 10:04:00', 'signup_at' => '2026-11-15 10:05:00', 'account_at' => '2026-11-15 10:06:00', 'votes' => 2, 'comments' => 1, 'posts_created' => 1]);
    analyticsVisit('late', ['network_day' => '2026-11-16', 'started_at' => '2026-11-16 10:01:00', 'votes' => 1]);
    analyticsVisit('voter', ['votes' => 3]);
    analyticsVisit('clicker', ['channel' => TrafficChannel::Network, 'source_id' => $source->id, 'source_key' => 'paid:'.$source->id, 'spend' => '0.25', 'spend_estimated' => true]);
    analyticsVisit('clicker', ['channel' => TrafficChannel::Network, 'source_id' => $source->id, 'source_key' => 'paid:'.$source->id, 'spend' => '0.10', 'network_day' => '2026-11-16']);

    $figures = analyticsQuery()->figures(analyticsFilter('30d'));

    expect([$figures->visitors, $figures->starters, $figures->completers, $figures->goal, $figures->signupForm, $figures->signups])->toBe([4, 2, 1, 1, 2, 2])
        ->and([$figures->accounts, $figures->maturedAccounts, $figures->activated])->toBe([2, 1, 1])
        ->and([$figures->votes, $figures->voters, $figures->comments, $figures->commenters, $figures->posts])->toBe([6, 2, 1, 1, 1])
        ->and([$figures->spend, $figures->spendEstimated])->toBe(['0.35000000', '0.25000000'])
        ->and($figures->startRate())->toBe(0.5)
        ->and($figures->completion())->toBe(0.5)
        ->and($figures->activationRate())->toBe(0.25);
});

it('counts a person in each source they came through, and once in all, with the visits that carried tags', function () {
    analyticsVisit('ann', ['source_key' => 'reddit:organic', 'utm_source' => 'reddit']);
    analyticsVisit('ann', ['source_key' => 'google:organic', 'network_day' => '2026-11-16']);
    analyticsVisit('bob', ['source_key' => 'reddit:organic']);
    analyticsVisit('bob', ['source_key' => 'reddit:organic', 'network_day' => '2026-11-17']);

    $by = analyticsQuery()->by(analyticsFilter(), AnalyticsDimension::Source);
    $rows = collect($by['rows'])->keyBy(fn (array $row): string => (string) $row['keys']['source_key']);

    expect($rows->keys()->all())->toBe(['reddit:organic', 'google:organic'])
        ->and($rows['reddit:organic']['figures']->visitors)->toBe(2)
        ->and([$rows['reddit:organic']['visits'], $rows['reddit:organic']['tagged']])->toBe([3, 1])
        ->and(AnalyticsSource::basis('reddit:organic', $rows['reddit:organic']['visits'], $rows['reddit:organic']['tagged']))->toBe('33% tagged · 67% referrer')
        ->and($rows['google:organic']['figures']->visitors)->toBe(1)
        ->and($by['rest'])->toBeNull()
        ->and(analyticsQuery()->figures(analyticsFilter())->visitors)->toBe(2);
});

it('narrows to a source and a campaign together, and to a device', function () {
    analyticsVisit('ann', ['source_key' => 'reddit:organic', 'campaign_key' => 'tag:launch']);
    analyticsVisit('bob', ['source_key' => 'reddit:organic', 'campaign_key' => 'tag:autumn']);
    analyticsVisit('cat', ['source_key' => 'google:organic', 'campaign_key' => 'tag:launch']);
    analyticsVisit('dan', ['source_key' => 'reddit:organic', 'campaign_key' => 'tag:launch', 'device' => TrafficDevice::Desktop]);

    $query = analyticsQuery();

    expect($query->figures(analyticsFilter(source: 'reddit:organic', campaign: 'tag:launch'))->visitors)->toBe(2)
        ->and($query->figures(analyticsFilter(source: 'reddit:organic'))->visitors)->toBe(3)
        ->and($query->figures(analyticsFilter(campaign: 'tag:launch'))->visitors)->toBe(3)
        ->and($query->figures(analyticsFilter(device: TrafficDevice::Mobile, source: 'reddit:organic', campaign: 'tag:launch'))->visitors)->toBe(1)
        ->and($query->figures(analyticsFilter(source: 'google:organic', campaign: 'tag:autumn'))->isEmpty())->toBeTrue()
        ->and($query->figures(analyticsFilter(source: 'reddit:organic', campaign: 'tag:launch')->periodAndDevice())->visitors)->toBe(4);
});

it('counts the people past the top groups together, each once, those of no group among them', function () {
    foreach (['a1', 'a2', 'a3', 'a4', 'a5'] as $browser) {
        analyticsVisit($browser, ['source_key' => 'reddit:organic']);
    }

    foreach (['b1', 'b2', 'b3', 'b4'] as $browser) {
        analyticsVisit($browser, ['source_key' => 'google:organic']);
    }

    // p came through two of the smaller sources, a1 through a top one as well.
    analyticsVisit('p', ['source_key' => 'facebook:organic']);
    analyticsVisit('q', ['source_key' => 'facebook:organic', 'utm_source' => 'fb']);
    analyticsVisit('p', ['source_key' => 'bing:organic', 'network_day' => '2026-11-16']);
    analyticsVisit('r', ['source_key' => 'bing:organic']);
    analyticsVisit('a1', ['source_key' => 'bing:organic', 'network_day' => '2026-11-16']);
    // A visit recorded before visits kept their source.
    analyticsVisit('z', ['source_key' => null]);

    $top = analyticsQuery()->by(analyticsFilter(), AnalyticsDimension::Source, 2);
    $all = analyticsQuery()->by(analyticsFilter(), AnalyticsDimension::Source, 10);

    expect(array_column(array_column($top['rows'], 'keys'), 'source_key'))->toBe(['reddit:organic', 'google:organic'])
        ->and(array_map(fn (array $row): int => $row['figures']->visitors, $top['rows']))->toBe([5, 4])
        ->and($top['rest']['figures']->visitors)->toBe(5)
        ->and([$top['rest']['visits'], $top['rest']['tagged']])->toBe([6, 1])
        ->and(AnalyticsSource::otherLabel($top['rest']['tagged']))->toBe('Other sources')
        ->and($all['rows'])->toHaveCount(5)
        ->and($all['rest'])->toBeNull();
});

it('counts the rest of a split on two columns by both, and leaves a top group of no value out of it', function () {
    foreach (['a', 'b', 'c'] as $browser) {
        analyticsVisit($browser, ['country' => 'US', 'city' => 'Austin']);
    }

    analyticsVisit('d', ['country' => 'US', 'city' => 'Boston']);
    analyticsVisit('e', ['country' => 'US', 'city' => null]);
    // The same city's name in another country is another city.
    analyticsVisit('f', ['country' => 'DE', 'city' => 'Austin']);

    foreach (['u1', 'u2', 'u3', 'u4'] as $browser) {
        analyticsVisit($browser, ['country' => null]);
    }

    $cities = analyticsQuery()->by(analyticsFilter(), AnalyticsDimension::City, 2);
    $countries = analyticsQuery()->by(analyticsFilter(), AnalyticsDimension::Country, 2);

    expect(array_column($cities['rows'], 'keys'))->toBe([['city' => null, 'country' => null], ['city' => 'Austin', 'country' => 'US']])
        ->and($cities['rest']['figures']->visitors)->toBe(3)
        ->and(array_column($countries['rows'], 'keys'))->toBe([['country' => 'US'], ['country' => null]])
        ->and($countries['rest']['figures']->visitors)->toBe(1);
});

it('splits by game and version as numbers, along an opened path', function () {
    $version = GameVersion::factory()->create();
    analyticsVisit('ann', ['game_id' => $version->game_id, 'version_id' => $version->id, 'campaign_key' => 'tag:launch']);
    analyticsVisit('bob', ['game_id' => $version->game_id, 'version_id' => $version->id, 'campaign_key' => 'tag:autumn']);
    analyticsVisit('cat', ['campaign_key' => 'tag:launch']);

    $filter = analyticsFilter()->within(AnalyticsDimension::Campaign, ['campaign_key' => 'tag:launch']);
    $rows = analyticsQuery()->by($filter, AnalyticsDimension::Version)['rows'];

    expect(array_column($rows, 'keys'))->toContain(['version_id' => $version->id], ['version_id' => null])
        ->and(collect($rows)->firstWhere('keys.version_id', $version->id)['figures']->visitors)->toBe(1)
        ->and(analyticsQuery()->by($filter, AnalyticsDimension::Game)['rows'])->toHaveCount(2);
});

it('counts the people of each hour by their arrival, the day’s first and last hour taking any earlier and later one', function () {
    analyticsVisit('ann', ['network_day' => '2026-11-17', 'arrived_at' => '2026-11-17 00:30:00']);
    analyticsVisit('bob', ['network_day' => '2026-11-17', 'arrived_at' => '2026-11-17 13:10:00']);
    analyticsVisit('ann', ['network_day' => '2026-11-17', 'arrived_at' => '2026-11-17 13:50:00']);
    analyticsVisit('cat', ['network_day' => '2026-11-17', 'arrived_at' => '2026-11-17 23:59:00']);
    // Network days of a time zone behind and ahead of UTC.
    analyticsVisit('dan', ['network_day' => '2026-11-17', 'arrived_at' => '2026-11-18 03:00:00']);
    analyticsVisit('eve', ['network_day' => '2026-11-17', 'arrived_at' => '2026-11-16 22:00:00']);

    $filter = analyticsFilter('yesterday');
    $series = analyticsQuery()->series($filter, $filter->period->buckets());

    expect($series)->toHaveCount(24)
        ->and([$series[0], $series[13], $series[23]])->toBe([2, 2, 2])
        ->and(array_sum($series))->toBe(6);
});

it('counts the people of each day by its network day, a person on two days on each', function () {
    analyticsVisit('ann', ['network_day' => '2026-11-12']);
    analyticsVisit('ann', ['network_day' => '2026-11-12', 'arrived_at' => '2026-11-12 18:00:00']);
    analyticsVisit('bob', ['network_day' => '2026-11-12']);
    analyticsVisit('ann', ['network_day' => '2026-11-15']);
    analyticsVisit('cat', ['network_day' => '2026-11-18', 'arrived_at' => '2026-11-18 08:00:00']);

    $filter = analyticsFilter();

    expect(analyticsQuery()->series($filter, $filter->period->buckets()))->toBe([2, 0, 0, 1, 0, 0, 1])
        ->and(analyticsQuery()->series($filter, []))->toBe([]);
});

it('counts the people of each block of days between its first and last day', function () {
    analyticsVisit('ann', ['network_day' => '2026-10-01']);
    analyticsVisit('ann', ['network_day' => '2026-10-03']);
    analyticsVisit('bob', ['network_day' => '2026-10-07']);
    analyticsVisit('cat', ['network_day' => '2026-10-08']);
    analyticsVisit('ann', ['network_day' => '2026-11-13']);

    $filter = analyticsFilter(from: '2026-10-01', to: '2026-11-14');
    $buckets = $filter->period->buckets();

    expect($buckets)->toHaveCount(7)
        ->and($buckets[6]['days'])->toBe(3)
        ->and(analyticsQuery()->series($filter, $buckets))->toBe([2, 1, 0, 0, 0, 0, 1]);
});

it('counts who came back: on two days of the period, or seen before it from anywhere', function () {
    analyticsVisit('twice', ['network_day' => '2026-11-13', 'source_key' => 'reddit:organic']);
    analyticsVisit('twice', ['network_day' => '2026-11-15', 'source_key' => 'google:organic']);
    analyticsVisit('before', ['source_key' => 'reddit:organic']);
    analyticsVisit('before', ['network_day' => '2026-11-01', 'source_key' => 'google:organic']);
    analyticsVisit('sameday');
    analyticsVisit('sameday', ['arrived_at' => '2026-11-15 18:00:00']);
    analyticsVisit('neverran');
    analyticsVisit('neverran', ['network_day' => '2026-11-01', 'opened_at' => null]);
    analyticsVisit('once');

    expect(analyticsQuery()->returning(analyticsFilter()))->toBe(2)
        // On reddit, “twice” came on one day only; “before” was still seen before.
        ->and(analyticsQuery()->returning(analyticsFilter(source: 'reddit:organic')))->toBe(1)
        ->and(analyticsQuery()->figures(analyticsFilter())->withReturning(analyticsQuery()->returning(analyticsFilter()))->returning)->toBe(2);
});

it('counts what the people leave out: the unattributed, and the paid clicks of crawlers and pages that never ran', function () {
    $source = TrafficSource::factory()->create();
    $paid = ['channel' => TrafficChannel::Network, 'source_id' => $source->id, 'source_key' => 'paid:'.$source->id];
    analyticsVisit('crawler', [...$paid, 'opened_at' => null, 'bot_flags' => BotSignals::CRAWLER | BotSignals::NO_BROWSER_HEADERS, 'spend' => '0.05']);
    analyticsVisit('gone', [...$paid, 'opened_at' => null, 'bot_flags' => BotSignals::HEADLESS, 'spend' => '0.05', 'spend_estimated' => true]);
    analyticsVisit('buyer', [...$paid, 'spend' => '0.10']);
    analyticsVisit('nobody');
    analyticsVisit('redditor', ['source_key' => 'reddit:organic']);

    expect(analyticsQuery()->quality(analyticsFilter()))->toBe([
        'visitors' => 3,
        'direct' => 1,
        'paidClicks' => 3,
        'crawlers' => 1,
        'neverRan' => 1,
        'spend' => '0.20000000',
        'spendEstimated' => '0.05000000',
    ]);
});

it('counts the attempts played on the period’s visits, and the results their share buttons shared', function () {
    $version = GameVersion::factory()->create();
    $inside = analyticsVisit('ann', ['game_id' => $version->game_id]);
    $alsoInside = analyticsVisit('bob', ['game_id' => $version->game_id, 'network_day' => '2026-11-16']);
    $outside = analyticsVisit('cat', ['game_id' => $version->game_id, 'network_day' => '2026-11-01']);
    $attempt = fn (TrafficVisit $visit): GameAttempt => GameAttempt::factory()->create(['version_id' => $version->id, 'traffic_visit_id' => $visit->id, 'visitor_hash' => $visit->visitor_hash]);
    $event = fn (GameAttempt $played, GameEventType $type) => (new GameEvent)->forceFill([
        'game_id' => $played->game_id, 'version_id' => $played->version_id, 'attempt_id' => $played->id, 'audience' => GameAudience::Anonymous,
        'visitor_hash' => $played->visitor_hash, 'type' => $type, 'provider' => 'reddit', 'occurred_at' => now(),
    ])->save();

    $shared = $attempt($inside);
    $event($shared, GameEventType::ShareClicked);
    $event($shared, GameEventType::ShareClicked);
    $event($shared, GameEventType::ResultViewed);
    $attempt($inside);
    $event($attempt($alsoInside), GameEventType::ShareClicked);
    $event($attempt($outside), GameEventType::ShareClicked);

    expect(analyticsQuery()->attempts(analyticsFilter()))->toBe(3)
        ->and(analyticsQuery()->resultShares(analyticsFilter()))->toBe(['clicks' => 3, 'results' => 2])
        ->and(analyticsQuery()->attempts(analyticsFilter('yesterday')))->toBe(0)
        ->and(analyticsQuery()->resultShares(analyticsFilter('yesterday')))->toBe(['clicks' => 0, 'results' => 0]);
});

it('reads the window before the period with the same conditions', function () {
    analyticsVisit('ann', ['network_day' => '2026-11-08', 'source_key' => 'reddit:organic']);
    analyticsVisit('bob', ['network_day' => '2026-11-09', 'source_key' => 'google:organic']);
    analyticsVisit('cat', ['source_key' => 'reddit:organic']);

    $previous = analyticsFilter(source: 'reddit:organic')->previous();

    expect($previous)->not->toBeNull()
        ->and(analyticsQuery()->figures($previous)->visitors)->toBe(1);
});

it('offers the values the most people came through, the most first, without counting anything else', function () {
    foreach (['ann', 'bob', 'cat', 'hal'] as $browser) {
        analyticsVisit($browser, ['source_key' => 'reddit:organic', 'campaign_key' => 'tag:launch']);
    }

    analyticsVisit('ann', ['source_key' => 'reddit:organic', 'network_day' => '2026-11-16']);
    analyticsVisit('dan', ['source_key' => 'google:organic', 'campaign_key' => 'tag:launch']);
    analyticsVisit('eve', ['source_key' => 'google:organic', 'campaign_key' => 'tag:launch']);
    analyticsVisit('fay', ['source_key' => 'google:organic']);
    analyticsVisit('fay', ['source_key' => 'bing:organic']);
    // Its page never ran: nobody.
    analyticsVisit('gus', ['source_key' => 'x:organic', 'opened_at' => null]);

    expect(array_column(analyticsQuery()->top(analyticsFilter(), AnalyticsDimension::Source, 10), 'source_key'))->toBe(['reddit:organic', 'google:organic', 'bing:organic'])
        ->and(analyticsQuery()->top(analyticsFilter(), AnalyticsDimension::Source, 2))->toBe([['source_key' => 'reddit:organic'], ['source_key' => 'google:organic']])
        ->and(array_column(analyticsQuery()->top(analyticsFilter(source: 'google:organic'), AnalyticsDimension::Campaign, 10), 'campaign_key'))->toBe(['tag:launch', null])
        ->and(analyticsQuery()->top(analyticsFilter('yesterday'), AnalyticsDimension::Source, 10))->toBe([]);
});

it('tells whether a filter admits anyone, and when the first visit of all was recorded', function () {
    expect(analyticsQuery()->recordedSince())->toBeNull()
        ->and(analyticsQuery()->has(analyticsFilter()))->toBeFalse();

    analyticsVisit('ann', ['source_key' => 'reddit:organic', 'campaign_key' => 'tag:launch']);
    // A paid click whose page never ran was still recorded, and first.
    TrafficVisit::factory()->create(['arrived_at' => '2026-11-02 08:30:00', 'network_day' => '2026-11-02']);

    expect(analyticsQuery()->recordedSince()?->toDateTimeString())->toBe('2026-11-02 08:30:00')
        ->and(analyticsQuery()->has(analyticsFilter(source: 'reddit:organic', campaign: 'tag:launch')))->toBeTrue()
        ->and(analyticsQuery()->has(analyticsFilter(source: 'google:organic', campaign: 'tag:launch')))->toBeFalse()
        ->and(analyticsQuery()->has(analyticsFilter('yesterday')))->toBeFalse();
});
