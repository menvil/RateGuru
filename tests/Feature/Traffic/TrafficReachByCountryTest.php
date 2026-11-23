<?php

use App\Actions\Traffic\SyncTrafficNetworkAction;
use App\Enums\TrafficConnectionStatus;
use App\Enums\TrafficConnector;
use App\Enums\TrafficSpendOrigin;
use App\Filament\Pages\TrafficSourcesPage;
use App\Models\TrafficApiConnection;
use App\Models\TrafficCampaign;
use App\Models\TrafficSource;
use App\Models\TrafficSpendDay;
use App\Models\TrafficSpendDayCountry;
use App\Models\User;
use App\Queries\Traffic\TrafficReachQuery;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Livewire\Livewire;

/*
 * A network's impressions by country: read beside the day's spend without
 * ever touching it, and set against the visits located in that country.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-17 12:00:00'));
    Http::preventStrayRequests();
    Sleep::fake();
    $this->actingAs(User::factory()->admin()->create());
    $this->source = TrafficSource::factory()->create(['name' => 'PopAds main', 'connector' => TrafficConnector::PopAds]);
    $this->campaign = TrafficCampaign::factory()->create(['source_id' => $this->source->id, 'external_id' => '881204']);
});

/** PopAds answering its reports: the rows of the country report given, and spend to match. */
function popAdsAnswering(Closure|array $byCountry): void
{
    Http::fake(function (Request $request) use ($byCountry) {
        if (! str_contains($request->url(), 'report_advertiser')) {
            return Http::response(['data' => ['general_information' => ['name' => 'US popunder']]]);
        }

        parse_str($request->body(), $form);

        if (str_contains((string) $form['groups'], 'country')) {
            return is_array($byCountry) ? Http::response(['rows' => $byCountry]) : $byCountry((string) substr((string) $form['start'], 0, 10));
        }

        return Http::response(['rows' => str_contains((string) $form['groups'], 'website')
            ? [['campaign_id' => '881204', 'website_id' => '40123', 'cost' => '1.00', 'impressions' => 100, 'datetime' => substr((string) $form['start'], 0, 10)]]
            : [['campaign_id' => '881204', 'cost' => '1.00']]]);
    });
}

function connectedPopAds(TrafficSource $source, array $attributes = []): TrafficApiConnection
{
    return TrafficApiConnection::factory()->create(['source_id' => $source->id, 'next_sync_at' => now(), 'backfill_cursor' => null, ...$attributes]);
}

it('keeps a day’s impressions by country beside its spend, for the campaigns RateGuru saw', function () {
    popAdsAnswering([
        ['datetime' => '2026-11-16', 'country' => 'DE', 'campaign_id' => '881204', 'website_id' => '40123', 'impressions' => 60, 'cost' => '0.60'],
        ['datetime' => '2026-11-16', 'country' => 'us', 'campaign_id' => '881204', 'website_id' => '40123', 'impressions' => 40, 'cost' => '0.40'],
        ['datetime' => '2026-11-16', 'country' => 'FR', 'campaign_id' => '999', 'website_id' => '40123', 'impressions' => 7, 'cost' => '0.07'],
        ['datetime' => '2026-11-15', 'country' => 'PL', 'campaign_id' => '881204', 'website_id' => '40123', 'impressions' => 5, 'cost' => '0.05'],
    ]);
    $connection = connectedPopAds($this->source);

    app(SyncTrafficNetworkAction::class)->handle($connection, CarbonImmutable::now());

    $figures = TrafficSpendDayCountry::query()->where('network_day', '2026-11-16')->orderBy('country')->get();

    expect($figures->pluck('country')->all())->toBe(['DE', 'US'])
        ->and($figures[0]->campaign_id)->toBe($this->campaign->id)
        ->and($figures[0]->site_ref)->toBe('40123')
        ->and($figures[0]->impressions)->toBe(60)
        ->and($figures[0]->amount)->toBe('0.60000000')
        ->and(TrafficSpendDay::query()->where('network_day', '2026-11-16')->where('origin', TrafficSpendOrigin::Api)->sole()->amount)->toBe('1.00000000');
});

it('writes nothing when the same figures come again', function () {
    popAdsAnswering([['datetime' => '2026-11-16', 'country' => 'DE', 'campaign_id' => '881204', 'website_id' => '40123', 'impressions' => 60, 'cost' => '0.60']]);
    $connection = connectedPopAds($this->source);
    app(SyncTrafficNetworkAction::class)->handle($connection, CarbonImmutable::now());
    $id = TrafficSpendDayCountry::query()->where('network_day', '2026-11-16')->sole()->id;

    app(SyncTrafficNetworkAction::class)->handle($connection->fresh(), CarbonImmutable::now()->addMinutes(5));

    expect(TrafficSpendDayCountry::query()->where('network_day', '2026-11-16')->sole()->id)->toBe($id);
});

it('leaves the turn’s spend and the connection alone when the country report fails', function (Closure $failure) {
    popAdsAnswering($failure);
    paidImpressionsIn('DE', $this->source, '2026-11-16', 9, '0.09', ['campaign_id' => $this->campaign->id, 'site_ref' => '40123']);
    $connection = connectedPopAds($this->source, ['country_cursor' => '2026-11-10']);

    app(SyncTrafficNetworkAction::class)->handle($connection, CarbonImmutable::now());

    expect(TrafficSpendDay::query()->where('network_day', '2026-11-16')->where('origin', TrafficSpendOrigin::Api)->count())->toBe(1)
        ->and($connection->fresh()->status)->toBe(TrafficConnectionStatus::Connected)
        ->and($connection->fresh()->failures)->toBe(0)
        ->and($connection->fresh()->country_cursor)->toBe('2026-11-10')
        ->and(TrafficSpendDayCountry::query()->where('network_day', '2026-11-16')->sole()->impressions)->toBe(9);
})->with([
    'refused' => [fn () => fn () => Http::response(['errors' => [['title' => 'nope']]], 500)],
    'rows it cannot read' => [fn () => fn (string $day) => Http::response(['rows' => [['datetime' => $day, 'campaign_id' => '881204', 'impressions' => 5]]])],
]);

it('catches up on older days a few at a time, from the first the API reported', function () {
    popAdsAnswering(fn (string $day) => Http::response(['rows' => [['datetime' => $day, 'country' => 'DE', 'campaign_id' => '881204', 'website_id' => '40123', 'impressions' => 10, 'cost' => '0.10']]]));
    paidImpressions($this->source, '2026-11-10', 100, '1.00', ['campaign_id' => $this->campaign->id, 'site_ref' => '40123']);
    $connection = connectedPopAds($this->source);

    app(SyncTrafficNetworkAction::class)->handle($connection, CarbonImmutable::now());

    expect($connection->fresh()->country_cursor)->toBe('2026-11-13')
        ->and(TrafficSpendDayCountry::query()->pluck('network_day')->sort()->values()->all())->toBe(['2026-11-10', '2026-11-11', '2026-11-12', '2026-11-16', '2026-11-17']);

    app(SyncTrafficNetworkAction::class)->handle($connection->fresh(), CarbonImmutable::now()->addMinutes(5));
    app(SyncTrafficNetworkAction::class)->handle($connection->fresh(), CarbonImmutable::now()->addMinutes(10));

    expect($connection->fresh()->country_cursor)->toBe('2026-11-16')
        ->and(TrafficSpendDayCountry::query()->count())->toBe(8);
});

it('sets one country’s impressions against the visits located there, of the same campaign and site', function () {
    paidImpressions($this->source, '2026-11-16', 100, '1.00', ['campaign_id' => $this->campaign->id, 'site_ref' => '40123']);
    paidImpressionsIn('DE', $this->source, '2026-11-16', 60, '0.60', ['campaign_id' => $this->campaign->id, 'site_ref' => '40123']);
    paidImpressionsIn('US', $this->source, '2026-11-16', 40, '0.40', ['campaign_id' => $this->campaign->id, 'site_ref' => '40123']);
    reachedVisits($this->source, '2026-11-16', 30, 20, 10, ['campaign_id' => $this->campaign->id, 'site_ref' => '40123', 'country' => 'DE']);
    reachedVisits($this->source, '2026-11-16', 5, 0, 0, ['campaign_id' => $this->campaign->id, 'site_ref' => '40123', 'country' => 'US']);
    reachedVisits($this->source, '2026-11-16', 4, 0, 0, ['campaign_id' => $this->campaign->id, 'site_ref' => '77777', 'country' => 'DE']);

    $germany = app(TrafficReachQuery::class)->tally('2026-11-16', '2026-11-16', country: 'DE')->total();
    $everywhere = app(TrafficReachQuery::class)->tally('2026-11-16', '2026-11-16')->total();

    expect([$germany->impressions, $germany->reached, $germany->opened, $germany->started])->toBe([60, 30, 20, 10])
        ->and($germany->amount)->toBe('0.60000000')
        ->and([$everywhere->impressions, $everywhere->reached])->toBe([100, 35]);
});

it('leaves out a country’s figures for a day a file or a typed total counts from', function () {
    paidImpressions($this->source, '2026-11-16', 100, '1.00', ['campaign_id' => $this->campaign->id, 'site_ref' => '40123', 'origin' => TrafficSpendOrigin::Csv]);
    paidImpressions($this->source, '2026-11-16', 100, '1.00', ['campaign_id' => $this->campaign->id, 'site_ref' => '40123']);
    paidImpressionsIn('DE', $this->source, '2026-11-16', 60, '0.60', ['campaign_id' => $this->campaign->id, 'site_ref' => '40123']);
    paidImpressions($this->source, '2026-11-15', 50, '0.50', ['campaign_id' => $this->campaign->id, 'site_ref' => '40123']);
    paidImpressionsIn('DE', $this->source, '2026-11-15', 20, '0.20', ['campaign_id' => $this->campaign->id, 'site_ref' => '40123']);

    expect(app(TrafficReachQuery::class)->tally('2026-11-15', '2026-11-16', country: 'DE')->total()->impressions)->toBe(20);
});

it('shows a country’s paid impressions and reach in Sites & zones and on the way here, and marks no site Low reach', function () {
    config(['traffic.reach.min_impressions' => 10]);
    paidImpressions($this->source, '2026-11-16', 200, '2.00', ['campaign_id' => $this->campaign->id, 'site_ref' => '40123']);
    paidImpressionsIn('DE', $this->source, '2026-11-16', 60, '0.60', ['campaign_id' => $this->campaign->id, 'site_ref' => '40123']);
    paidImpressionsIn('US', $this->source, '2026-11-16', 140, '1.40', ['campaign_id' => $this->campaign->id, 'site_ref' => '40123']);
    reachedVisits($this->source, '2026-11-16', 30, 15, 0, ['campaign_id' => $this->campaign->id, 'site_ref' => '40123', 'country' => 'DE']);
    reachedVisits($this->source, '2026-11-16', 7, 0, 0, ['campaign_id' => $this->campaign->id, 'site_ref' => '40123', 'country' => 'US']);

    $page = Livewire::withQueryParams(['period' => '30d', 'view' => 'sites', 'list' => 'site', 'country' => 'de'])->test(TrafficSourcesPage::class)->call('load');
    $row = collect($page->viewData('list')['rows'])->keyBy('ref')['40123'];

    expect($page->get('country'))->toBe('DE')
        ->and([$row['reach']->impressions, $row['reach']->reached])->toBe([60, 30])
        ->and($row['state'])->not->toBe('low_reach')
        ->and($page->viewData('reach')['split'])->toBeFalse()
        ->and($page->viewData('reach')['steps'][0]['count'])->toBe(60)
        ->and($page->viewData('reach')['steps'][1]['count'])->toBe(30);

    $page->assertSeeHtml('data-testid="traffic-sites-reach-note"')
        ->assertSeeText('Paid impr. and Reached are this country’s. Low reach is judged across all countries, so no site is marked on this filter.')
        ->assertSeeInOrder(['All countries', 'United States', '140', 'Germany', '60']);

    expect(array_column($page->viewData('countryOptions'), 'value'))->toBe(['', 'US', 'DE']);
});

it('says so when the network reported nothing for the country on screen', function () {
    paidImpressions($this->source, '2026-11-16', 200, '2.00', ['campaign_id' => $this->campaign->id, 'site_ref' => '40123']);
    paidImpressionsIn('US', $this->source, '2026-11-16', 200, '2.00', ['campaign_id' => $this->campaign->id, 'site_ref' => '40123']);
    reachedVisits($this->source, '2026-11-16', 3, 0, 0, ['campaign_id' => $this->campaign->id, 'site_ref' => '40123', 'country' => 'DE']);

    $page = Livewire::withQueryParams(['period' => '30d', 'view' => 'sites', 'list' => 'site', 'country' => 'DE'])->test(TrafficSourcesPage::class)->call('load');

    expect(collect($page->viewData('list')['rows'])->keyBy('ref')['40123']['reach'])->toBeNull()
        ->and($page->viewData('reach')['split'])->toBeTrue()
        ->and(array_column($page->viewData('countryOptions'), 'value'))->toBe(['', 'US', 'DE']);

    $page->assertSeeText('The network reported no impressions for this country, so they’re left out on these filters.')
        ->assertSeeText('The network reported no impressions for this country. On these filters Paid impr. and Reached show “—” and no site is marked Low reach.');
});

it('still leaves the impressions out with a device on top of a country', function () {
    paidImpressions($this->source, '2026-11-16', 200, '2.00', ['campaign_id' => $this->campaign->id, 'site_ref' => '40123']);
    paidImpressionsIn('DE', $this->source, '2026-11-16', 200, '2.00', ['campaign_id' => $this->campaign->id, 'site_ref' => '40123']);
    reachedVisits($this->source, '2026-11-16', 3, 0, 0, ['campaign_id' => $this->campaign->id, 'site_ref' => '40123', 'country' => 'DE', 'device' => 'mobile']);

    $page = Livewire::withQueryParams(['period' => '30d', 'view' => 'sites', 'list' => 'site', 'country' => 'DE', 'device' => 'mobile'])->test(TrafficSourcesPage::class)->call('load');

    expect(collect($page->viewData('list')['rows'])->keyBy('ref')['40123']['reach'])->toBeNull()
        ->and($page->viewData('reach')['split'])->toBeTrue();

    $page->assertSeeText('The network’s impressions aren’t split by device, city or game, so they’re left out on these filters.');
});

it('ignores a country it does not know in the address', function () {
    $page = Livewire::withQueryParams(['country' => 'not-a-country'])->test(TrafficSourcesPage::class)->call('load');

    expect($page->get('country'))->toBe('')
        ->and($page->instance()->filter()->where)->not->toHaveKey('country');
});

it('opens every tab on a country', function (string $tab) {
    reachedVisits($this->source, '2026-11-16', 2, 1, 0, ['campaign_id' => $this->campaign->id, 'site_ref' => '40123', 'country' => 'DE']);

    Livewire::withQueryParams(['tab' => $tab, 'period' => '30d', 'country' => 'DE'])->test(TrafficSourcesPage::class)->call('load')->assertOk();
})->with(array_keys((new ReflectionClassConstant(TrafficSourcesPage::class, 'TABS'))->getValue()));

it('keeps a country’s figures no longer than the spend they belong to', function () {
    config(['traffic.retention_days.spend_days' => 30]);
    paidImpressionsIn('DE', $this->source, '2026-09-01', 5, '0.05', ['campaign_id' => $this->campaign->id]);
    paidImpressionsIn('DE', $this->source, '2026-11-16', 5, '0.05', ['campaign_id' => $this->campaign->id]);

    $this->artisan('traffic:purge-expired')->assertSuccessful();

    expect(DB::table('traffic_spend_day_countries')->pluck('network_day')->all())->toBe(['2026-11-16']);
});
