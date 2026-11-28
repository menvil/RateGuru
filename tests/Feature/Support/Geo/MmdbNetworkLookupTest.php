<?php

use App\Enums\NetworkKind;
use App\Support\Geo\NetworkLookup;
use Illuminate\Support\Facades\Log;

/**
 * The network an address belongs to: its ASN and organisation from the
 * networks' database, hosting where the hosting list names that ASN, relay
 * where iCloud Private Relay's ranges hold the address — whatever network
 * carries it — and unknown with no database, for an address that is not
 * public, or for a file that breaks.
 */
beforeEach(function () {
    geoDirectory();
});

afterEach(function () {
    removeGeoDirectory($this);
});

it('names the network, hosting where the list names it, relay where Private Relay’s ranges hold the address', function () {
    installGeoDatabase('dbip_asn_lite', 'GeoLite2-ASN-Test.mmdb');
    installNetworkLists([1221], ['1.128.0.0/30']);
    $networks = app(NetworkLookup::class);

    $hosted = $networks->lookup('1.128.0.9');
    $relay = $networks->lookup('1.128.0.2');
    $home = $networks->lookup('12.81.92.1');

    expect([$hosted->asn, $hosted->organization, $hosted->kind, $hosted->isHosting()])->toBe([1221, 'Telstra Pty Ltd', NetworkKind::Hosting, true])
        ->and([$relay->asn, $relay->kind, $relay->isHosting()])->toBe([1221, NetworkKind::Relay, false])
        ->and([$home->asn, $home->kind])->toBe([7018, NetworkKind::Other])
        ->and($networks->lookup('2600:6000::1')->asn)->toBe(237);
});

it('answers unknown for an address that is not public, and for one the database doesn’t know', function (?string $ip) {
    installGeoDatabase('dbip_asn_lite', 'GeoLite2-ASN-Test.mmdb');
    installNetworkLists([1221], ['10.0.0.0/8']);

    expect(app(NetworkLookup::class)->lookup($ip))->asn->toBeNull()->kind->toBe(NetworkKind::Unknown);
})->with([null, '', 'not an address', '10.0.0.1', '127.0.0.1', '::1', '81.2.69.142']);

it('says relay from the ranges alone, and unknown otherwise, while the networks’ database is missing', function () {
    installNetworkLists([1221], ['1.128.0.0/30']);
    $networks = app(NetworkLookup::class);

    expect($networks->lookup('1.128.0.2')->kind)->toBe(NetworkKind::Relay)
        ->and($networks->lookup('1.128.0.9'))->asn->toBeNull()->kind->toBe(NetworkKind::Unknown);
});

it('names the network with no lists at all, never hosting or relay', function () {
    installGeoDatabase('dbip_asn_lite', 'GeoLite2-ASN-Test.mmdb');

    expect(app(NetworkLookup::class)->lookup('1.128.0.9'))->asn->toBe(1221)->kind->toBe(NetworkKind::Other);
});

it('answers unknown for a networks’ database that breaks, warns once and stops trying for the rest of the request', function (string $fixture, ?string $contents) {
    installGeoDatabase('dbip_asn_lite', $fixture);

    if ($contents !== null) {
        file_put_contents(test()->geoDirectory.'/dbip_asn_lite/current.mmdb', $contents);
    }

    Log::spy();
    $networks = app(NetworkLookup::class);

    // Both addresses lead the reader into the fixture's broken pointers; one warning for two lookups shows the second never reached the file.
    expect($networks->lookup('1.1.1.16')->kind)->toBe(NetworkKind::Unknown)
        ->and($networks->lookup('1.1.1.32')->kind)->toBe(NetworkKind::Unknown);
    Log::shouldHaveReceived('warning')->once();
})->with([
    'a corrupt record' => ['MaxMind-DB-test-broken-pointers-24.mmdb', null],
    'a file that is no database' => ['GeoLite2-ASN-Test.mmdb', 'not a database'],
]);
