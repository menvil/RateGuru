<?php

use App\Enums\GeoEdition;
use App\Filament\Pages\TrafficSourcesPage;
use App\Models\User;
use App\Services\Geo\GeoAttribution;
use App\Support\Geo\Update\GeoStateStore;
use Carbon\CarbonImmutable;
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
