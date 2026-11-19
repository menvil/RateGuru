<?php

use App\Support\Geo\GeoLookup;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    geoDirectory();
});

afterEach(function () {
    removeGeoDirectory($this);
});

it('places an address with the installed database', function () {
    installGeoDatabase();
    $geo = app(GeoLookup::class);

    $london = $geo->lookup('81.2.69.142');
    $milton = $geo->lookup('216.160.83.56');
    $japan = $geo->lookup('2001:218::');

    expect([$london->countryCode, $london->countryName, $london->region, $london->city, $london->timeZone])->toBe(['GB', 'United Kingdom', 'ENG', 'London', 'Europe/London'])
        ->and([$milton->countryCode, $milton->region, $milton->city])->toBe(['US', 'WA', 'Milton'])
        ->and([$japan->countryCode, $japan->city])->toBe(['JP', null]);
});

it('answers unknown for an address that is not public, without opening the file', function (?string $ip) {
    installGeoDatabase('geolite2_city', 'MaxMind-DB-test-broken-pointers-24.mmdb');
    Log::spy();

    expect(app(GeoLookup::class)->lookup($ip)->isKnown())->toBeFalse();
    Log::shouldNotHaveReceived('warning');
})->with([null, '', 'not an address', '10.0.0.1', '127.0.0.1', '192.168.1.20', '::1', 'fe80::1']);

it('answers unknown when there is no database at all', function () {
    expect(app(GeoLookup::class)->lookup('81.2.69.142')->isKnown())->toBeFalse();
});

it('answers unknown for a file that is not a database, and stops trying for the rest of the request', function () {
    installGeoDatabase();
    file_put_contents(test()->geoDirectory.'/geolite2_city/current.mmdb', 'not a database');
    Log::spy();
    $geo = app(GeoLookup::class);

    expect($geo->lookup('81.2.69.142')->isKnown())->toBeFalse()
        ->and($geo->lookup('216.160.83.56')->isKnown())->toBeFalse();
    Log::shouldHaveReceived('warning')->once();
});

it('answers unknown for a corrupt record instead of failing the page', function () {
    installGeoDatabase('geolite2_city', 'MaxMind-DB-test-broken-pointers-24.mmdb');

    expect(app(GeoLookup::class)->lookup('1.1.1.1')->isKnown())->toBeFalse();
});

it('reads the other edition while the chosen one has no database', function () {
    installGeoDatabase('dbip_city_lite');

    expect(app(GeoLookup::class)->lookup('81.2.69.142')->countryCode)->toBe('GB');
});
