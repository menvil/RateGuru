<?php

use App\Filament\Pages\Analytics\GeographyTab;
use App\Filament\Pages\GrowthAnalyticsPage;
use App\Filament\Pages\TrafficSourcesPage;
use App\Models\TrafficCampaign;
use App\Models\TrafficSource;
use App\Models\User;
use App\Services\Geo\GeoAttribution;
use App\Support\Analytics\CampaignKey;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * Analytics → Geography: the ten countries the most people came from, each
 * with its flag — a globe for Kosovo — its share of everyone and its rates,
 * registration and activation of new visitors, "—" below the denominator a
 * row needs; every other country together and those no IP database placed
 * last, whatever the sorting. The country the most people came from is open
 * unless another or none is chosen: its cities of at least twenty people,
 * the rest of the country together. Sorted by people or by either rate of
 * new visitors, those whose rate can't be shown last; every filter of the
 * page applies; how a place is known is said with the credit of the database
 * serving, and that no place is known while none serves. Nobody matching is
 * said once; the page reads no visit until it is in a browser, and the tab
 * a bounded number of queries.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-18 12:00:00'));
    geoDirectory();
    $this->actingAs(User::factory()->admin()->create());
});

afterEach(function () {
    removeGeoDirectory($this);
});

/** The open Geography tab's figures, read as the page reads them once it is in a browser. */
function analyticsGeography(array $query = []): array
{
    return Livewire::withQueryParams(['tab' => 'geo', ...$query])->test(GrowthAnalyticsPage::class)->call('load')->viewData('data');
}

/** The table's rows as text: the kind, the flag, the name, the visitors, the share and the three rates. */
function geoRows(array $data): array
{
    return array_map(fn (array $row): string => implode(' | ', [$row['kind'], $row['flag'] ?? '-', $row['name'], $row['visitors'], $row['share'], ...array_column($row['rates'], 'value')]), $data['rows']);
}

/**
 * Browsers that each came once from a place — a country and a city, either
 * none — doing what the attributes given for their place say.
 *
 * @param  callable(int): array<string, mixed>|null  $did
 */
function geoVisitors(string $name, int $count, ?string $country, ?string $city = null, ?callable $did = null): void
{
    foreach (range(1, $count) as $n) {
        analyticsVisit($name.'-'.$n, ['country' => $country, 'city' => $city, ...($did === null ? [] : $did($n))]);
    }
}

it('lists the ten countries the most people came from, then every other country together, each person once, and those no database placed', function () {
    foreach (['US' => 13, 'DE' => 12, 'GB' => 11, 'FR' => 10, 'ES' => 9, 'IT' => 8, 'NL' => 7, 'PL' => 6, 'SE' => 5, 'NO' => 4, 'BR' => 3, 'JP' => 2] as $code => $count) {
        geoVisitors($code, $count, $code);
    }

    // Someone seen in two of the other countries is one of their people.
    analyticsVisit('BR-1', ['country' => 'JP', 'network_day' => '2026-11-16']);
    geoVisitors('nowhere', 4, null);

    $data = analyticsGeography();
    $rows = geoRows($data);

    expect($rows)->toHaveCount(13)
        ->and(array_slice($rows, 0, 3))->toBe([
            'country | 🇺🇸 | United States | 13 | 13.8% | — | — | —',
            // The country the most people came from is open: too few people to name a city, all of them are the rest.
            'others | - | Other cities | 13 | 13.8% | — | — | —',
            'country | 🇩🇪 | Germany | 12 | 12.8% | — | — | —',
        ])
        ->and(array_column(array_slice($data['rows'], 2, 9), 'name'))->toBe(['Germany', 'United Kingdom', 'France', 'Spain', 'Italy', 'Netherlands', 'Poland', 'Sweden', 'Norway'])
        ->and(array_slice($rows, -2))->toBe([
            'other | 🌐 | Other countries | 5 | 5.3% | — | — | —',
            'unknown | — | Location unknown | 4 | 4.3% | — | — | —',
        ])
        ->and($data['note'])->toBe('Last 30 days · 94 unique visitors')
        ->and($data['foot'])->toBe('Start rate uses visitors; registration and activation use new visitors. Click a country to see its cities. A visitor seen in two places counts in each, and a rate shows “—” until the number it divides by reaches 30.')
        // Every bar against the row of the most people.
        ->and(array_column($data['rows'], 'bar'))->toBe([100, 100, 92, 85, 77, 69, 62, 54, 46, 38, 31, 38, 31]);
});

it('opens the cities of a country with twenty people or more, the rest of the country together, one country at a time', function () {
    geoVisitors('berlin', 25, 'DE', 'Berlin');
    geoVisitors('hamburg', 20, 'DE', 'Hamburg');
    geoVisitors('munich', 18, 'DE', 'Munich');
    // Someone of Berlin seen in Munich too: still too few there to name it.
    analyticsVisit('berlin-1', ['country' => 'DE', 'city' => 'Munich', 'network_day' => '2026-11-16']);
    geoVisitors('somewhere', 3, 'DE');
    // The same city's name in another country is that country's.
    geoVisitors('austin', 21, 'US', 'Berlin');

    $cities = fn (array $data): array => array_values(array_map(fn (array $row): string => $row['name'].' '.$row['visitors'], array_filter($data['rows'], fn (array $row): bool => $row['kind'] !== 'country')));

    $data = analyticsGeography();

    expect(array_map(fn (array $row): string => $row['kind'].' '.$row['name'].' '.$row['visitors'], $data['rows']))->toBe([
        'country Germany 66',
        'city Berlin 25',
        'city Hamburg 20',
        // The smaller city and the people of no known city.
        'others Other cities 22',
        'country United States 21',
    ])
        ->and(array_column($data['rows'], 'open'))->toBe([true, false, false, false, false])
        // A click closes the country open, or opens another.
        ->and(array_column($data['rows'], 'toggle'))->toBe([GeographyTab::NONE_OPEN, null, null, null, 'US'])
        ->and($data['rows'][1]['share'])->toBe('28.7%')
        ->and($cities(analyticsGeography(['open' => 'US'])))->toBe(['Berlin 21'])
        ->and($cities(analyticsGeography(['open' => GeographyTab::NONE_OPEN])))->toBe([])
        // A country listed nowhere opens the one the most people came from.
        ->and($cities(analyticsGeography(['open' => 'FR'])))->toBe(['Berlin 25', 'Hamburg 20', 'Other cities 22']);

    Livewire::withQueryParams(['tab' => 'geo'])->test(GrowthAnalyticsPage::class)->call('load')
        ->assertSeeHtml('aria-expanded="true"')
        ->set('open', 'US')
        ->assertSet('open', 'US')
        ->assertViewHas('data', fn (array $data): bool => $data['rows'][0]['open'] === false && $data['rows'][1]['name'] === 'United States' && $data['rows'][1]['open'] === true)
        ->set('open', GeographyTab::NONE_OPEN)
        ->assertViewHas('data', fn (array $data): bool => collect($data['rows'])->every(fn (array $row): bool => $row['kind'] === 'country' && ! $row['open']));
});

it('sorts countries and cities by people or by a rate of new visitors, those whose rate can’t be shown last, other countries and the unplaced at the end', function () {
    $account = fn (int $accounts, int $activated = 0): Closure => fn (int $n): array => $n <= $accounts
        ? ['signup_at' => '2026-11-02 10:00:00', 'account_at' => '2026-11-02 10:00:00', 'activated_at' => $n <= $activated ? '2026-11-04 10:00:00' : null]
        : [];

    // Canada: two cities, the larger one with fewer accounts.
    geoVisitors('toronto', 33, 'CA', 'Toronto', $account(1));
    geoVisitors('montreal', 31, 'CA', 'Montreal', $account(4));
    geoVisitors('austria', 40, 'AT', null, $account(2, 2));
    geoVisitors('belgium', 35, 'BE', null, $account(7, 1));
    // Too few new visitors for either rate.
    geoVisitors('denmark', 10, 'DK', null, $account(5, 5));
    geoVisitors('nowhere', 2, null, null, $account(2, 2));

    $order = fn (array $query): array => array_map(fn (array $row): string => $row['name'].' '.$row['rates'][1]['value'].' '.$row['rates'][2]['value'], analyticsGeography($query)['rows']);

    expect($order([]))->toBe([
        'Canada 7.8% 0.0%',
        'Toronto 3.0% 0.0%',
        'Montreal 12.9% 0.0%',
        'Austria 5.0% 5.0%',
        'Belgium 20.0% 2.9%',
        'Denmark — —',
        'Location unknown — —',
    ])
        ->and($order(['gsort' => 'registration']))->toBe([
            'Belgium 20.0% 2.9%',
            'Canada 7.8% 0.0%',
            'Montreal 12.9% 0.0%',
            'Toronto 3.0% 0.0%',
            'Austria 5.0% 5.0%',
            'Denmark — —',
            'Location unknown — —',
        ])
        ->and($order(['gsort' => 'activation']))->toBe([
            'Austria 5.0% 5.0%',
            'Belgium 20.0% 2.9%',
            'Canada 7.8% 0.0%',
            'Toronto 3.0% 0.0%',
            'Montreal 12.9% 0.0%',
            'Denmark — —',
            'Location unknown — —',
        ])
        ->and(analyticsGeography()['rows'][0]['rates'][1]['title'])->toBe('5 of 64');

    Livewire::withQueryParams(['tab' => 'geo'])->test(GrowthAnalyticsPage::class)->call('load')
        ->set('gsort', 'registration')
        ->assertSet('gsort', 'registration')
        ->assertViewHas('data', fn (array $data): bool => $data['sort'] === 'registration' && $data['rows'][0]['name'] === 'Belgium');
});

it('draws a country’s flag from its code, a globe for Kosovo and for every other country, and a dash for the unplaced', function () {
    geoVisitors('kosovo', 3, 'XK');
    geoVisitors('japan', 2, 'JP');
    geoVisitors('nowhere', 1, null);

    expect(array_map(fn (array $row): string => $row['flag'].' '.$row['name'], analyticsGeography(['open' => GeographyTab::NONE_OPEN])['rows']))->toBe([
        '🌐 Kosovo',
        '🇯🇵 Japan',
        '— Location unknown',
    ]);
});

it('reads every filter of the page, and says so once when nobody matches', function () {
    $exo = TrafficSource::factory()->create(['name' => 'ExoClick']);
    $campaign = TrafficCampaign::factory()->create(['source_id' => $exo->id, 'name' => 'Spring push']);
    analyticsVisit('ann', ['country' => 'DE', 'source_key' => 'reddit:organic', 'campaign_key' => 'tag:spring', 'device' => 'desktop']);
    analyticsVisit('bob', ['country' => 'DE', 'source_key' => 'reddit:organic']);
    analyticsVisit('cat', ['country' => 'US', 'source_key' => 'paid:'.$exo->id, 'campaign_key' => CampaignKey::paid($campaign->id)]);
    analyticsVisit('dan', ['country' => 'FR', 'network_day' => '2026-10-01', 'arrived_at' => '2026-10-01 10:00:00']);

    $countries = fn (array $query): array => array_values(array_map(fn (array $row): string => $row['name'].' '.$row['visitors'], array_filter(analyticsGeography(['open' => GeographyTab::NONE_OPEN, ...$query])['rows'], fn (array $row): bool => $row['kind'] === 'country')));

    expect($countries([]))->toBe(['Germany 2', 'United States 1'])
        ->and($countries(['device' => 'desktop']))->toBe(['Germany 1'])
        ->and($countries(['source' => 'reddit:organic', 'campaign' => 'tag:spring']))->toBe(['Germany 1'])
        ->and($countries(['source' => 'paid:'.$exo->id]))->toBe(['United States 1'])
        ->and($countries(['from' => '2026-09-25', 'to' => '2026-10-05']))->toBe(['France 1'])
        // The card says what it counts.
        ->and(analyticsGeography(['device' => 'desktop', 'source' => 'reddit:organic', 'campaign' => 'tag:spring'])['note'])->toBe('Last 30 days · Desktop · Reddit · spring · 1 unique visitor')
        ->and(analyticsGeography(['source' => 'paid:'.$exo->id, 'campaign' => CampaignKey::paid($campaign->id)])['note'])->toBe('Last 30 days · ExoClick · Spring push · 1 unique visitor')
        ->and(analyticsGeography(['from' => '2026-09-25', 'to' => '2026-10-05'])['note'])->toBe('25 Sep — 05 Oct 2026 · 1 unique visitor');

    $empty = Livewire::withQueryParams(['tab' => 'geo', 'source' => 'bing:organic', 'device' => 'mobile'])->test(GrowthAnalyticsPage::class)->call('load');

    expect($empty->viewData('data'))->toMatchArray(['empty' => true, 'range' => '20 Oct — 18 Nov 2026', 'filtered' => true]);

    $empty->assertSee('No visitors for these filters')
        ->assertSee('Nothing matches in 20 Oct — 18 Nov 2026. Pick a longer range or clear the filters.')
        ->assertDontSee('Countries and cities')
        ->assertDontSee('Country and city come from the IP address')
        ->call('clearFilters')
        ->assertSet('source', '')
        ->assertSee('Countries and cities');
});

it('says how a place is known, crediting the database serving, and while none serves that visits get no place', function () {
    analyticsVisit('ann', ['country' => 'DE']);

    // Nothing serves yet: what MaxMind's licence asks stands, beside the warning and the way to Tracking.
    Livewire::withQueryParams(['tab' => 'geo'])->test(GrowthAnalyticsPage::class)->call('load')
        ->assertSee('No IP database yet: visits have no country or city until one is fetched.')
        ->assertViewHas('data', fn (array $data): bool => $data['place'] === [
            'serving' => false,
            'credit' => ['text' => GeoAttribution::MAXMIND_CREDIT, 'url' => null],
            'least' => 20,
            'tracking' => TrafficSourcesPage::getUrl(['tab' => 'sources']),
        ])
        ->assertSee('Country and city come from the IP address at each visit and are approximate. The raw IP isn’t stored. Cities with fewer than 20 visitors are grouped into “Other cities” so single people can’t be picked out.')
        ->assertSee(GeoAttribution::MAXMIND_CREDIT);

    installGeoDatabase('dbip_city_lite');

    Livewire::withQueryParams(['tab' => 'geo'])->test(GrowthAnalyticsPage::class)->call('load')
        ->assertDontSee('No IP database yet')
        ->assertSeeHtml('<a href="https://db-ip.com" target="_blank" rel="noopener" class="rg-admin-analytics__geo-credit">IP Geolocation by DB-IP</a>')
        ->assertDontSee('GeoLite Data created by MaxMind');

    installGeoDatabase();

    Livewire::withQueryParams(['tab' => 'geo'])->test(GrowthAnalyticsPage::class)->call('load')
        ->assertDontSee('No IP database yet')
        ->assertSee(GeoAttribution::MAXMIND_CREDIT)
        ->assertDontSee('IP Geolocation by DB-IP');

    // With nobody to place, the warning still stands while nothing serves, and nothing else is drawn.
    removeGeoDirectory($this);
    geoDirectory();

    Livewire::withQueryParams(['tab' => 'geo', 'device' => 'desktop'])->test(GrowthAnalyticsPage::class)->call('load')
        ->assertSee('No IP database yet')
        ->assertSee('No visitors for these filters')
        ->assertDontSee(GeoAttribution::MAXMIND_CREDIT);
});

it('keeps the sorting and the open country in the address, the Geography tab’s alone, anything else read as the default', function () {
    $page = fn (array $query): mixed => Livewire::withQueryParams($query)->test(GrowthAnalyticsPage::class);

    $page(['tab' => 'geo', 'gsort' => 'activation', 'open' => 'DE'])->assertSet('gsort', 'activation')->assertSet('open', 'DE');
    $page(['tab' => 'geo', 'open' => GeographyTab::NONE_OPEN])->assertSet('open', GeographyTab::NONE_OPEN);

    foreach ([['gsort' => 'spend'], ['gsort' => ['visitors']], ['open' => 'de'], ['open' => 'ZZ'], ['open' => 'DEU'], ['open' => ['DE']]] as $forged) {
        $page(['tab' => 'geo', ...$forged])->assertSet('gsort', 'visitors')->assertSet('open', '');
    }

    $page(['tab' => 'overview', 'gsort' => 'activation', 'open' => 'DE'])->assertSet('gsort', 'visitors')->assertSet('open', '');

    // Leaving the tab leaves its state behind.
    $page(['tab' => 'geo', 'gsort' => 'registration', 'open' => 'US'])->set('tab', 'landings')->assertSet('gsort', 'visitors')->assertSet('open', '');
});

it('reads no visit until the page is in a browser, and the tab in a bounded number of queries', function () {
    $queries = function (array $query): int {
        $page = Livewire::withQueryParams(['tab' => 'geo', ...$query])->test(GrowthAnalyticsPage::class);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $page->call('load');
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    geoVisitors('berlin', 21, 'DE', 'Berlin');
    $few = $queries(['open' => GeographyTab::NONE_OPEN]);
    $open = $queries([]);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->get(GrowthAnalyticsPage::getUrl(['tab' => 'geo']))->assertOk()->assertDontSee('Countries and cities');
    expect(collect(DB::getQueryLog())->pluck('query')->filter(fn (string $query): bool => str_contains($query, 'traffic_visits'))->values()->all())->toBe([]);
    DB::disableQueryLog();

    foreach (['US', 'GB', 'FR', 'ES', 'IT', 'NL', 'PL', 'SE', 'NO', 'BR', 'JP', 'IN'] as $code) {
        geoVisitors($code, 2, $code, 'Capital');
    }

    geoVisitors('nowhere', 1, null);
    $many = $queries([]);
    $narrowed = $queries(['source' => 'direct', 'device' => 'mobile']);

    // Ten countries, Berlin under the first, every other country and the unplaced.
    expect(analyticsGeography()['rows'])->toHaveCount(GeographyTab::COUNTRIES + 3)
        // Everyone, the countries with the rest and the unplaced, then the open country's cities with theirs: never one per row.
        ->and($few)->toBeLessThanOrEqual(9)
        ->and($open - $few)->toBeLessThanOrEqual(2)
        ->and($many)->toBeLessThanOrEqual(12)
        ->and($narrowed)->toBeLessThanOrEqual(12);
});
