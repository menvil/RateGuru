<?php

use App\Enums\TrafficActor;
use App\Enums\TrafficBlockLevel;
use App\Enums\TrafficBlockMethod;
use App\Enums\TrafficCampaignStage;
use App\Enums\TrafficChannel;
use App\Enums\TrafficDevice;
use App\Enums\TrafficDimension;
use App\Enums\TrafficKnownReason;
use App\Enums\TrafficSpendOrigin;
use App\Models\Game;
use App\Models\GameVersion;
use App\Models\TrafficBlockEvent;
use App\Models\TrafficCampaign;
use App\Models\TrafficCampaignStagePeriod;
use App\Models\TrafficConversion;
use App\Models\TrafficPostback;
use App\Models\TrafficSource;
use App\Models\TrafficSpendDay;
use App\Models\TrafficVisit;
use App\Queries\Traffic\TrafficLogsQuery;
use App\Queries\Traffic\TrafficReportQuery;
use App\Queries\Traffic\TrafficSpendQuery;
use App\Queries\Users\UnconfirmedAccountsQuery;
use App\Support\Traffic\Reports\SignupFigures;
use App\Support\Traffic\Reports\TrafficReportFilter;
use App\Support\Traffic\Reports\VisitFilter;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-17 12:00:00'));
    $this->source = TrafficSource::factory()->create();
    $this->filter = new TrafficReportFilter('2026-11-10', '2026-11-17');
});

/** A visit of the period, on the given network day. */
function reportVisit(TrafficSource $source, array $attributes = []): TrafficVisit
{
    return TrafficVisit::factory()->create(['source_id' => $source->id, 'arrived_at' => '2026-11-15 10:00:00', 'network_day' => '2026-11-15', 'opened_at' => '2026-11-15 10:00:00', ...$attributes]);
}

it('adds the period up by device and type, with how its accounts came', function () {
    reportVisit($this->source, ['spend' => '0.10', 'account_at' => now(), 'signup_at' => now(), 'signup_place' => 'post', 'signup_after_seconds' => 900, 'value_real' => '0.5']);
    reportVisit($this->source, ['spend' => '0.10', 'known' => true, 'device' => TrafficDevice::Desktop]);
    reportVisit($this->source, ['spend' => '0.10', 'opened_at' => null]);
    TrafficVisit::factory()->unpaid()->create(['game_id' => Game::factory(), 'arrived_at' => '2026-11-15 11:00:00', 'network_day' => '2026-11-15', 'opened_at' => now()]);
    // Outside the period: its network day is another, whatever its arrival.
    reportVisit($this->source, ['network_day' => '2026-11-09', 'arrived_at' => '2026-11-10 02:00:00']);

    $rows = collect(app(TrafficReportQuery::class)->summary($this->filter));
    $paidMobile = $rows->first(fn (array $row) => $row['device'] === 'mobile' && $row['channel'] === 'network');

    expect($rows)->toHaveCount(3)
        ->and($paidMobile['figures']->visits)->toBe(2)
        ->and($paidMobile['figures']->opened)->toBe(1)
        ->and($paidMobile['figures']->accounts)->toBe(1)
        ->and($paidMobile['figures']->spend)->toBe('0.20000000')
        ->and($paidMobile['path']['place_post'])->toBe(1)
        ->and($paidMobile['path']['within_an_hour'])->toBe(1)
        ->and($paidMobile['path']['within_10_minutes'])->toBe(0)
        ->and($rows->first(fn (array $row) => $row['device'] === 'desktop')['figures']->knownOpened)->toBe(1);
});

it('splits a level of the tree under its path, biggest first', function () {
    $other = TrafficSource::factory()->create();
    reportVisit($this->source, ['country' => 'US', 'city' => 'Austin', 'account_at' => now()]);
    reportVisit($this->source, ['country' => 'US', 'city' => 'Boston']);
    reportVisit($other, ['country' => 'DE', 'city' => 'Berlin']);

    $sources = app(TrafficReportQuery::class)->level($this->filter, TrafficDimension::Source);
    $cities = app(TrafficReportQuery::class)->level($this->filter->within(TrafficDimension::Source, ['source_id' => $this->source->id, 'channel' => 'network'])->within(TrafficDimension::Country, ['country' => 'US']), TrafficDimension::City);

    expect(array_column(array_column($sources, 'keys'), 'source_id'))->toBe([$this->source->id, $other->id])
        ->and(array_column(array_column($cities, 'keys'), 'city'))->toBe(['Austin', 'Boston']);
});

it('orders groups that tie by every column they are grouped by', function () {
    $game = Game::factory()->create();

    foreach ([TrafficChannel::Search, TrafficChannel::Referral, TrafficChannel::Direct] as $channel) {
        TrafficVisit::factory()->unpaid($channel)->create(['game_id' => $game->id, 'arrived_at' => '2026-11-15 10:00:00', 'network_day' => '2026-11-15', 'signup_at' => now(), 'account_at' => now()]);
    }

    $channels = fn (array $rows): array => array_column(array_column($rows, 'keys'), 'channel');

    expect($channels(app(TrafficReportQuery::class)->signups($this->filter, TrafficDimension::Source)))->toBe(['direct', 'referral', 'search'])
        ->and($channels(app(TrafficReportQuery::class)->level($this->filter, TrafficDimension::Source)))->toBe(['direct', 'referral', 'search']);
});

it('lists the networks’ sites with enough clicks, and the campaign they came through', function () {
    $campaign = TrafficCampaign::factory()->create(['source_id' => $this->source->id]);
    reportVisit($this->source, ['site_ref' => 's1', 'zone_ref' => 'z1', 'campaign_id' => $campaign->id]);
    reportVisit($this->source, ['site_ref' => 's1', 'zone_ref' => 'z2', 'campaign_id' => $campaign->id]);
    reportVisit($this->source, ['site_ref' => 's2', 'zone_ref' => 'z3']);

    $sites = app(TrafficReportQuery::class)->zones($this->filter, false, minVisits: 2);
    $zones = app(TrafficReportQuery::class)->zones($this->filter, true);

    expect($sites)->toHaveCount(1)
        ->and($sites[0])->toMatchArray(['site_ref' => 's1', 'campaign_id' => $campaign->id, 'campaigns' => 1])
        ->and($sites[0]['figures']->visits)->toBe(2)
        ->and($zones)->toHaveCount(3);
});

it('gives each network site and zone its first click, keyed as its block, a site’s the first of all its zones’', function () {
    reportVisit($this->source, ['site_ref' => 's1', 'zone_ref' => 'z1', 'arrived_at' => '2026-11-14 08:00:00', 'network_day' => '2026-11-14']);
    reportVisit($this->source, ['site_ref' => 's1', 'zone_ref' => 'z1', 'arrived_at' => '2026-11-16 08:00:00', 'network_day' => '2026-11-16']);
    reportVisit($this->source, ['site_ref' => 's1', 'zone_ref' => 'z2', 'arrived_at' => '2026-11-12 09:30:00', 'network_day' => '2026-11-12']);
    reportVisit($this->source, ['site_ref' => 's2', 'zone_ref' => null]);
    // Before the period, and traffic that isn't a network's: neither counts.
    reportVisit($this->source, ['site_ref' => 's2', 'arrived_at' => '2026-11-01 10:00:00', 'network_day' => '2026-11-01']);
    TrafficVisit::factory()->unpaid(TrafficChannel::Search)->create(['site_ref' => 's3', 'arrived_at' => '2026-11-15 10:00:00', 'network_day' => '2026-11-15']);

    $first = array_map(fn (CarbonImmutable $at): string => $at->toDateTimeString(), app(TrafficReportQuery::class)->firstClicks($this->filter));
    ksort($first);
    $id = $this->source->id;

    expect($first)->toBe([
        "{$id}:site:s1" => '2026-11-12 09:30:00',
        "{$id}:site:s2" => '2026-11-15 10:00:00',
        "{$id}:zone:z1" => '2026-11-14 08:00:00',
        "{$id}:zone:z2" => '2026-11-12 09:30:00',
    ]);
});

it('adds up campaigns, and each stage of one while it lasted', function () {
    $campaign = TrafficCampaign::factory()->create(['source_id' => $this->source->id]);
    TrafficCampaignStagePeriod::query()->create(['campaign_id' => $campaign->id, 'stage' => TrafficCampaignStage::Test, 'started_at' => '2026-11-14 00:00:00', 'ended_at' => '2026-11-15 12:00:00']);
    TrafficCampaignStagePeriod::query()->create(['campaign_id' => $campaign->id, 'stage' => TrafficCampaignStage::Scale, 'started_at' => '2026-11-15 12:00:00']);
    reportVisit($this->source, ['campaign_id' => $campaign->id, 'arrived_at' => '2026-11-15 10:00:00']);
    reportVisit($this->source, ['campaign_id' => $campaign->id, 'arrived_at' => '2026-11-15 13:00:00']);
    reportVisit($this->source, ['campaign_id' => $campaign->id, 'arrived_at' => '2026-11-15 14:00:00']);

    $stages = app(TrafficReportQuery::class)->stages($campaign->id);

    expect(app(TrafficReportQuery::class)->byCampaign($this->filter)[$campaign->id]->visits)->toBe(3)
        ->and(array_map(fn (array $stage) => [$stage['stage'], $stage['figures']->visits], $stages))->toBe([['test', 1], ['scale', 2]]);
});

it('splits a game’s traffic by type, source, version and device in one pass', function () {
    $game = Game::factory()->create();
    reportVisit($this->source, ['game_id' => $game->id, 'arrived_at' => now()->subDays(2), 'network_day' => '2026-11-15']);
    reportVisit($this->source, ['game_id' => $game->id, 'arrived_at' => now()->subDay(), 'network_day' => '2026-11-16']);
    TrafficVisit::factory()->unpaid(TrafficChannel::Search)->create(['game_id' => $game->id, 'arrived_at' => now()->subDay(), 'network_day' => '2026-11-16']);

    $rows = collect(readBothWays(TrafficReportQuery::class)->gameBreakdown($game->id, now()->subDays(30)->toImmutable()));
    $paid = $rows->firstWhere('source_id', $this->source->id);

    expect($rows)->toHaveCount(2)
        ->and($paid['figures']->visits)->toBe(2)
        ->and($paid['first_at']->toDateString())->toBe('2026-11-15');
});

it('narrows a game’s traffic, campaigns and sign-ups to a device and countries, and lists the countries its visits came from', function () {
    $game = Game::factory()->create();
    $campaign = TrafficCampaign::factory()->create(['source_id' => $this->source->id]);
    reportVisit($this->source, ['game_id' => $game->id, 'campaign_id' => $campaign->id, 'country' => 'DE', 'signup_at' => now()]);
    reportVisit($this->source, ['game_id' => $game->id, 'country' => 'FR', 'device' => TrafficDevice::Desktop]);
    reportVisit($this->source, ['game_id' => $game->id, 'country' => null]);
    // Another game's visit names no country of this one.
    reportVisit($this->source, ['country' => 'BR']);
    $since = now()->subDays(30)->toImmutable();
    $until = now()->addSecond()->toImmutable();
    $visits = fn (?VisitFilter $filter): int => collect(readBothWays(TrafficReportQuery::class)->gameBreakdown($game->id, $since, $until, $filter))->sum(fn (array $row): int => $row['figures']->visits);

    expect($visits(null))->toBe(3)
        ->and($visits(new VisitFilter(TrafficDevice::Mobile)))->toBe(2)
        ->and($visits(new VisitFilter(countries: ['DE', 'FR'])))->toBe(2)
        ->and($visits(new VisitFilter(TrafficDevice::Desktop, ['DE'])))->toBe(0)
        // Every country but those named, and the one not known.
        ->and($visits(new VisitFilter(countries: ['DE'], otherCountries: true)))->toBe(2)
        ->and(readBothWays(TrafficReportQuery::class)->gameCampaigns($game->id, $since, $until, new VisitFilter(countries: ['FR'])))->toBe([])
        ->and(readBothWays(TrafficReportQuery::class)->gameCampaigns($game->id, $since, $until, new VisitFilter(countries: ['DE'])))->toBe([$this->source->id => 1])
        ->and(readBothWays(TrafficReportQuery::class)->gameSignups($game->id, $since, $until, visits: new VisitFilter(TrafficDevice::Desktop))['visits'])->toBe(1)
        ->and(readBothWays(TrafficReportQuery::class)->gameSignups($game->id, $since, $until, visits: new VisitFilter(countries: ['DE']))['figures']->signedUp)->toBe(1)
        ->and(readBothWays(TrafficReportQuery::class)->gameCountries($game->id))->toBe(['DE', 'FR']);
});

it('reconciles a source’s days, and keeps spend no visit took', function () {
    reportVisit($this->source, ['cost' => '0.001', 'spend' => '0.25']);
    TrafficSpendDay::factory()->create(['source_id' => $this->source->id, 'network_day' => '2026-11-15', 'origin' => TrafficSpendOrigin::Manual, 'amount' => '1.00', 'allocated_amount' => '0.25', 'counted' => true, 'allocated_at' => now()]);
    TrafficSpendDay::factory()->create(['source_id' => $this->source->id, 'network_day' => '2026-11-15', 'origin' => TrafficSpendOrigin::Api, 'amount' => '0.90', 'counted' => false, 'allocated_at' => now()]);

    $days = app(TrafficSpendQuery::class)->days($this->source->id, '2026-11-10', '2026-11-17');

    expect($days['2026-11-15'])->toMatchArray(['clicks' => 1, 'costs' => '0.00100000', 'counted' => '0.25000000', 'counted_origin' => 'manual', 'allocated' => true])
        ->and($days['2026-11-15']['figures'])->toBe(['api' => '0.90000000', 'manual' => '1.00000000'])
        ->and(app(TrafficSpendQuery::class)->unallocated('2026-11-10', '2026-11-17'))->toBe([['source_id' => $this->source->id, 'campaign_id' => null, 'amount' => '0.75000000']]);
});

it('reconciles each day of the range on its own, newest first, and nothing outside it', function () {
    reportVisit($this->source, ['cost' => '0.001', 'spend' => '0.25']);
    TrafficSpendDay::factory()->create(['source_id' => $this->source->id, 'network_day' => '2026-11-15', 'origin' => TrafficSpendOrigin::Manual, 'amount' => '1.00', 'allocated_amount' => '0.25', 'counted' => true, 'allocated_at' => now()]);
    reportVisit($this->source, ['network_day' => '2026-11-12', 'arrived_at' => '2026-11-12 10:00:00', 'cost' => '0.002', 'spend' => '0.40']);
    TrafficSpendDay::factory()->create(['source_id' => $this->source->id, 'network_day' => '2026-11-12', 'origin' => TrafficSpendOrigin::Csv, 'amount' => '2.00', 'allocated_amount' => '0.40', 'counted' => true, 'allocated_at' => now()]);
    // After the range.
    reportVisit($this->source, ['network_day' => '2026-11-20', 'arrived_at' => '2026-11-20 10:00:00', 'cost' => '0.004', 'spend' => '0.80']);
    TrafficSpendDay::factory()->create(['source_id' => $this->source->id, 'network_day' => '2026-11-20', 'origin' => TrafficSpendOrigin::Manual, 'amount' => '5.00', 'allocated_amount' => '0', 'counted' => true, 'allocated_at' => now()]);

    $days = app(TrafficSpendQuery::class)->days($this->source->id, '2026-11-10', '2026-11-17');

    expect(array_keys($days))->toBe(['2026-11-15', '2026-11-12'])
        ->and($days['2026-11-15'])->toMatchArray(['clicks' => 1, 'costs' => '0.00100000', 'counted' => '0.25000000', 'counted_origin' => 'manual', 'figures' => ['manual' => '1.00000000']])
        ->and($days['2026-11-12'])->toMatchArray(['clicks' => 1, 'costs' => '0.00200000', 'counted' => '0.40000000', 'counted_origin' => 'csv', 'figures' => ['csv' => '2.00000000']])
        ->and(app(TrafficSpendQuery::class)->unallocated('2026-11-10', '2026-11-17'))->toBe([['source_id' => $this->source->id, 'campaign_id' => null, 'amount' => '2.35000000']]);
});

it('pages each log newest first, so rows neither move nor repeat from one page to the next', function (string $log) {
    $make = match ($log) {
        'clicks' => fn () => reportVisit($this->source),
        'milestones' => fn () => TrafficConversion::factory()->create(['visit_id' => reportVisit($this->source)->id]),
        'postbacks' => fn () => TrafficPostback::factory()->create(['conversion_id' => TrafficConversion::factory()->create(['visit_id' => reportVisit($this->source)->id])->id, 'created_at' => now()]),
        'blocks' => fn () => TrafficBlockEvent::query()->create(['source_id' => $this->source->id, 'kind' => 'block', 'level' => TrafficBlockLevel::Site, 'ref' => 's1', 'ref_count' => 1, 'method' => TrafficBlockMethod::Manual, 'actor' => TrafficActor::User, 'ok' => true, 'occurred_at' => now()]),
    };
    $ids = collect(range(1, TrafficLogsQuery::PER_PAGE + 3))->map(fn () => $make()->id)->reverse()->values()->all();
    $page = fn (int $number) => match ($log) {
        'clicks' => app(TrafficLogsQuery::class)->clicks($this->source->id, null, $number),
        'milestones' => app(TrafficLogsQuery::class)->milestones($this->source->id, $number),
        'postbacks' => app(TrafficLogsQuery::class)->postbacks(null, null, $number),
        'blocks' => app(TrafficLogsQuery::class)->blocks($this->source->id, $number),
    };

    $first = $page(1);
    $second = $page(2);

    expect($first->hasMorePages())->toBeTrue()
        ->and([...collect($first->items())->pluck('id')->all(), ...collect($second->items())->pluck('id')->all()])->toBe($ids)
        ->and($second->hasMorePages())->toBeFalse();
})->with(['clicks', 'milestones', 'postbacks', 'blocks']);

it('counts sign-ups never confirmed up to the moment the window closed and still waiting after it, and how soon the confirmed confirmed', function () {
    $cutoff = UnconfirmedAccountsQuery::cutoff()->toImmutable();
    $early = ['arrived_at' => '2026-11-10 02:00:00', 'network_day' => '2026-11-10'];
    // z1: one signed up exactly as the window closed, one a second later, one confirmed in under 10 minutes.
    reportVisit($this->source, [...$early, 'zone_ref' => 'z1', 'signup_method' => 'email', 'signup_place' => 'game', 'signup_at' => $cutoff]);
    reportVisit($this->source, [...$early, 'zone_ref' => 'z1', 'signup_method' => 'email', 'signup_place' => 'game', 'signup_at' => $cutoff->addSecond()]);
    reportVisit($this->source, ['zone_ref' => 'z1', 'signup_method' => 'email', 'signup_place' => 'post', 'signup_at' => now(), 'account_at' => now(), 'signup_after_seconds' => 100, 'account_after_seconds' => 500]);
    // z2: every one confirmed, on each edge of the times — 10 minutes, an hour, a day — and later.
    foreach ([600, 601, 3600, 86400, 86401] as $index => $seconds) {
        reportVisit($this->source, ['zone_ref' => 'z2', 'signup_method' => $index === 0 ? 'google' : 'email', 'signup_place' => 'game', 'signup_at' => now(), 'account_at' => now(), 'signup_after_seconds' => 100, 'account_after_seconds' => 100 + $seconds]);
    }
    // z3 signed nobody up, so it has no row.
    reportVisit($this->source, ['zone_ref' => 'z3']);

    $zones = collect(app(TrafficReportQuery::class)->signups($this->filter, TrafficDimension::Zone, $cutoff))->keyBy(fn (array $row) => $row['keys']['zone_ref']);
    $methods = collect(app(TrafficReportQuery::class)->signups($this->filter, TrafficDimension::SignupMethod, $cutoff))->mapWithKeys(fn (array $row) => [$row['keys']['signup_method'] => $row['figures']->signedUp]);
    $places = collect(app(TrafficReportQuery::class)->signups($this->filter, TrafficDimension::SignupPlace, $cutoff))->mapWithKeys(fn (array $row) => [$row['keys']['signup_place'] => $row['figures']->confirmed]);
    $z1 = $zones['z1']['figures'];
    $z2 = $zones['z2']['figures'];

    expect($zones->keys()->all())->toBe(['z2', 'z1'])
        ->and([$z1->signedUp, $z1->confirmed, $z1->neverConfirmed, $z1->waiting, $z1->confirmedIn10Minutes, $z1->confirmRate()])->toBe([3, 1, 1, 1, 1, 1 / 3])
        ->and([$z2->signedUp, $z2->confirmed, $z2->neverConfirmed, $z2->waiting])->toBe([5, 5, 0, 0])
        ->and([$z2->confirmedIn10Minutes, $z2->confirmedInAnHour, $z2->confirmedInADay, $z2->confirmedLater()])->toBe([1, 2, 1, 1])
        ->and($methods->all())->toBe(['email' => 7, 'google' => 1])
        ->and($places->all())->toBe(['game' => 5, 'post' => 1]);
});

it('splits sign-ups by each dimension, unpaid traffic by its channel, on the report’s filters', function (TrafficDimension $dimension, string $column, array $expected) {
    $campaign = TrafficCampaign::factory()->create(['source_id' => $this->source->id]);
    $game = Game::factory()->create();
    $signedUp = ['signup_at' => now(), 'signup_method' => 'email'];
    reportVisit($this->source, [...$signedUp, 'campaign_id' => $campaign->id, 'site_ref' => 's1', 'zone_ref' => 'z1', 'country' => 'US']);
    reportVisit($this->source, [...$signedUp, 'campaign_id' => $campaign->id, 'site_ref' => 's1', 'zone_ref' => 'z1', 'country' => 'US']);
    reportVisit($this->source, [...$signedUp, 'site_ref' => 's2', 'zone_ref' => 'z2', 'country' => 'DE', 'device' => TrafficDevice::Desktop]);
    TrafficVisit::factory()->unpaid(TrafficChannel::Search)->count(4)->create(['game_id' => $game->id, 'arrived_at' => '2026-11-15 10:00:00', 'network_day' => '2026-11-15', 'country' => 'FR', 'device' => TrafficDevice::Desktop, ...$signedUp]);
    TrafficVisit::factory()->unpaid(TrafficChannel::Direct)->create(['game_id' => $game->id, 'arrived_at' => '2026-11-15 10:00:00', 'network_day' => '2026-11-15', 'country' => 'FR', ...$signedUp]);

    $rows = app(TrafficReportQuery::class)->signups($this->filter, $dimension);

    // A campaign is named by its row, as the test made it.
    expect(array_map(fn (array $row) => $column === 'campaign_id' && $row['keys'][$column] === $campaign->id ? 'campaign' : $row['keys'][$column], $rows))->toBe($expected)
        ->and(array_sum(array_map(fn (array $row) => $row['figures']->signedUp, $rows)))->toBe(8);
})->with([
    'source' => [TrafficDimension::Source, 'channel', ['search', 'network', 'direct']],
    'campaign' => [TrafficDimension::Campaign, 'campaign_id', [null, 'campaign', null]],
    'site' => [TrafficDimension::Site, 'site_ref', [null, 's1', 's2']],
    'zone' => [TrafficDimension::Zone, 'zone_ref', [null, 'z1', 'z2']],
    'country' => [TrafficDimension::Country, 'country', ['FR', 'US', 'DE']],
    'device' => [TrafficDimension::Device, 'device', ['desktop', 'mobile']],
]);

it('narrows the sign-ups by the report’s filters, paid and unpaid alike', function () {
    $campaign = TrafficCampaign::factory()->create(['source_id' => $this->source->id]);
    $game = Game::factory()->create();
    reportVisit($this->source, ['signup_at' => now(), 'campaign_id' => $campaign->id, 'game_id' => $game->id]);
    reportVisit($this->source, ['signup_at' => now(), 'device' => TrafficDevice::Desktop]);
    TrafficVisit::factory()->unpaid(TrafficChannel::Search)->create(['arrived_at' => '2026-11-15 10:00:00', 'network_day' => '2026-11-15', 'signup_at' => now(), 'game_id' => $game->id]);
    // Outside the period: its network day is another.
    reportVisit($this->source, ['signup_at' => now(), 'network_day' => '2026-11-09', 'arrived_at' => '2026-11-10 02:00:00']);

    $signedUp = fn (array $where): int => array_sum(array_map(fn (array $row) => $row['figures']->signedUp, app(TrafficReportQuery::class)->signups(new TrafficReportFilter('2026-11-10', '2026-11-17', $where), TrafficDimension::Source)));

    expect($signedUp([]))->toBe(3)
        ->and($signedUp(['source_id' => null]))->toBe(1)
        ->and($signedUp(['channel' => 'network']))->toBe(2)
        ->and($signedUp(['source_id' => $this->source->id]))->toBe(2)
        ->and($signedUp(['campaign_id' => $campaign->id]))->toBe(1)
        ->and($signedUp(['game_id' => $game->id]))->toBe(2)
        ->and($signedUp(['device' => 'desktop']))->toBe(1);
});

it('adds the same sign-ups up in the summary as in the rows of any dimension', function () {
    $cutoff = UnconfirmedAccountsQuery::cutoff();
    reportVisit($this->source, ['arrived_at' => '2026-11-10 02:00:00', 'network_day' => '2026-11-10', 'signup_at' => '2026-11-10 03:00:00']);
    reportVisit($this->source, ['signup_at' => now(), 'account_at' => now(), 'signup_after_seconds' => 60, 'account_after_seconds' => 120, 'device' => TrafficDevice::Desktop]);
    TrafficVisit::factory()->unpaid(TrafficChannel::Direct)->create(['game_id' => Game::factory(), 'arrived_at' => '2026-11-15 10:00:00', 'network_day' => '2026-11-15', 'signup_at' => now()]);

    $summed = fn (iterable $rows) => collect($rows)->reduce(fn (SignupFigures $sum, array $row) => $sum->plus($row['signups'] ?? $row['figures']), new SignupFigures);
    $summary = app(TrafficReportQuery::class)->summary($this->filter, $cutoff);

    expect($summed($summary))->toEqual($summed(app(TrafficReportQuery::class)->signups($this->filter, TrafficDimension::Country, $cutoff)))
        ->and($summed($summary))->toEqual(new SignupFigures(signedUp: 3, confirmed: 1, neverConfirmed: 1, waiting: 1, confirmedIn10Minutes: 1))
        ->and($summed(array_filter($summary, fn (array $row) => $row['channel'] === 'network'))->signedUp)->toBe(2);
});

it('counts a game’s sign-ups from its visits in a window, of one version when asked', function () {
    $game = Game::factory()->create();
    $first = GameVersion::factory()->create(['game_id' => $game->id, 'number' => 1]);
    $second = GameVersion::factory()->create(['game_id' => $game->id, 'number' => 2]);
    $visit = fn (array $attributes) => TrafficVisit::factory()->create(['game_id' => $game->id, 'version_id' => $first->id, 'arrived_at' => now()->subDays(10), 'network_day' => '2026-11-07', ...$attributes]);
    $visit(['signup_at' => now()->subDays(10)]);
    $visit(['signup_at' => now()->subDay(), 'arrived_at' => now()->subDay(), 'network_day' => '2026-11-16']);
    $visit(['signup_at' => now(), 'account_at' => now(), 'version_id' => $second->id]);
    $visit([]);
    // A visitor signed in as they came is a logged-in player, not one of the anonymous visitors; one who signed in later is.
    $visit(['known' => true, 'known_reason' => TrafficKnownReason::SignedIn, 'signup_at' => now()]);
    $visit(['known' => true, 'known_reason' => TrafficKnownReason::Login]);
    // Before the window, and another game's.
    $visit(['signup_at' => now()->subDays(40), 'arrived_at' => now()->subDays(40), 'network_day' => '2026-10-08']);
    TrafficVisit::factory()->create(['signup_at' => now()]);

    $read = fn (?int $versionId) => readBothWays(TrafficReportQuery::class)->gameSignups($game->id, now()->subDays(30)->toImmutable(), now()->addSecond()->toImmutable(), $versionId);
    $all = $read(null);
    $ofFirst = $read($first->id);

    expect($all['visits'])->toBe(5)
        ->and([$all['figures']->signedUp, $all['figures']->confirmed, $all['figures']->neverConfirmed, $all['figures']->waiting])->toBe([3, 1, 1, 1])
        ->and($ofFirst['visits'])->toBe(4)
        ->and([$ofFirst['figures']->signedUp, $ofFirst['figures']->confirmed])->toBe([2, 0])
        ->and($read(999_999)['visits'])->toBe(0);
});
