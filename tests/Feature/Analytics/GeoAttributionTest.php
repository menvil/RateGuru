<?php

use App\Enums\GeoEdition;
use App\Filament\Pages\TrafficSourcesPage;
use App\Models\User;
use App\Services\Geo\GeoAttribution;
use App\Support\Geo\Update\GeoStateStore;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;

/*
 * Which IP database places the visitors and how its licence asks to be
 * credited, read once for every screen that shows countries and cities —
 * and Traffic sources → Sources reading it exactly as it did: what serves,
 * its build and last error, the warning while nothing serves, and the credit
 * of the edition serving.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-18 12:00:00'));
    // The figures as they are at each read: the read cache has tests of its own (AnalyticsReadCacheTest).
    config(['analytics.read_cache_minutes' => 0]);
    geoDirectory();
    $this->actingAs(User::factory()->admin()->create());
});

afterEach(function () {
    removeGeoDirectory($this);
});

function trafficSourcesTracking(): mixed
{
    return Livewire::withQueryParams(['tab' => 'sources'])->test(TrafficSourcesPage::class)->call('load')->assertOk();
}

it('has nothing serving before the first update, and Traffic sources warns of it as before', function () {
    expect(app(GeoAttribution::class)->read(GeoEdition::GeoLite2City))->toBe([
        'chosen' => GeoEdition::GeoLite2City,
        'serving' => null,
        'installed' => null,
        'checked' => null,
        'error' => null,
        'credit' => ['text' => GeoAttribution::MAXMIND_CREDIT, 'url' => null],
    ]);

    trafficSourcesTracking()
        ->assertSee('No IP database yet: visits have no country or city until one is fetched.')
        ->assertSee('Serving: none yet.')
        ->assertSee('This product includes GeoLite Data created by MaxMind, available from https://www.maxmind.com.');
});

it('reads the chosen edition serving with its last update, credited to MaxMind', function () {
    installGeoDatabase('geolite2_city');
    app(GeoStateStore::class)->write(GeoEdition::GeoLite2City, [
        'installed' => ['source' => 'npm', 'version' => '1.0.116'],
        'last_checked_at' => '2026-11-18T03:00:00+00:00',
        'last_error' => ['code' => 'download_failed'],
    ]);

    $geo = app(GeoAttribution::class)->read(GeoEdition::GeoLite2City);

    expect($geo['serving'])->toBe(GeoEdition::GeoLite2City)
        ->and($geo['installed'])->toBe(['source' => 'npm', 'version' => '1.0.116'])
        ->and($geo['checked'])->toBe('2026-11-18T03:00:00+00:00')
        ->and($geo['error'])->toBe('download_failed');

    trafficSourcesTracking()
        ->assertDontSee('No IP database yet')
        ->assertSee('Serving: GeoLite2 City · build 1.0.116 · last error: download_failed.')
        ->assertSee(GeoAttribution::MAXMIND_CREDIT)
        ->assertDontSee('IP Geolocation by DB-IP');
});

it('credits DB-IP with a link to it while its edition stands in', function () {
    installGeoDatabase('dbip_city_lite');

    $geo = app(GeoAttribution::class)->read(GeoEdition::GeoLite2City);

    expect([$geo['chosen'], $geo['serving']])->toBe([GeoEdition::GeoLite2City, GeoEdition::DbipCityLite])
        ->and($geo['credit'])->toBe(['text' => 'IP Geolocation by DB-IP', 'url' => 'https://db-ip.com'])
        ->and(GeoAttribution::credit(GeoEdition::GeoLite2City)['url'])->toBeNull();

    trafficSourcesTracking()
        ->assertSee('Serving: DB-IP City Lite.')
        ->assertSeeHtml('<a href="https://db-ip.com" target="_blank" rel="noopener">IP Geolocation by DB-IP</a>')
        ->assertDontSee('GeoLite Data created by MaxMind');
});

it('names the networks’ database and lists on Tracking with DB-IP’s credit, whichever city database serves', function () {
    trafficSourcesTracking()
        ->assertSee('Networks: DB-IP ASN Lite, none yet · no hosting list yet · no Private Relay list yet.')
        ->assertSeeHtml('<a href="https://db-ip.com" target="_blank" rel="noopener">IP to ASN data by DB-IP</a>');

    installGeoDatabase('geolite2_city');
    installGeoDatabase('dbip_asn_lite', 'GeoLite2-ASN-Test.mmdb');
    app(GeoStateStore::class)->write(GeoEdition::DbipAsnLite, ['installed' => ['source' => 'dbip', 'version' => '2026-11:asn-1'], 'last_error' => null]);
    installNetworkLists([3223, 9009, 45090], ['172.224.226.0/27']);
    foreach (['hosting' => 3, 'relay' => 1] as $list => $entries) {
        file_put_contents($this->geoDirectory.'/networks/'.$list.'.json', json_encode(['installed' => ['entries' => $entries]]));
    }

    expect(app(GeoAttribution::class)->networks())->toMatchArray(['hosting' => 3, 'relay' => 1, 'error' => null])
        ->and(app(GeoAttribution::class)->networks()['installed'])->toBe(['source' => 'dbip', 'version' => '2026-11:asn-1']);

    trafficSourcesTracking()
        ->assertSee('Networks: DB-IP ASN Lite, build 2026-11:asn-1 · 3 hosting and VPN networks · 1 Private Relay ranges.')
        ->assertSee(GeoAttribution::MAXMIND_CREDIT)
        ->assertSee('IP to ASN data by DB-IP');
});

it('shows the networks’ database’s last error before a first build serves, and each list’s', function () {
    File::ensureDirectoryExists($this->geoDirectory.'/dbip_asn_lite');
    app(GeoStateStore::class)->write(GeoEdition::DbipAsnLite, ['installed' => null, 'last_error' => ['code' => 'discovery_failed']]);
    installNetworkLists([3223]);
    file_put_contents($this->geoDirectory.'/networks/hosting.json', json_encode(['installed' => ['entries' => 1], 'last_error' => ['code' => 'list_invalid']]));
    unlink($this->geoDirectory.'/networks/relay.list');
    file_put_contents($this->geoDirectory.'/networks/relay.json', json_encode(['last_error' => ['code' => 'download_failed']]));

    trafficSourcesTracking()
        ->assertSee('Networks: DB-IP ASN Lite, none yet · last error: discovery_failed · 1 hosting and VPN networks (last error: list_invalid) · no Private Relay list yet (last error: download_failed).');
});

it('keeps both credits on Tracking whichever city database serves', function () {
    installGeoDatabase('geolite2_city');

    trafficSourcesTracking()
        ->assertSee(GeoAttribution::MAXMIND_CREDIT)
        ->assertSee('IP to ASN data by DB-IP');
});
