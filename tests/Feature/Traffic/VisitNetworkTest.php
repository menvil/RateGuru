<?php

use App\Models\TrafficSource;
use App\Models\TrafficVisit;
use App\Support\Traffic\Capture\BotSignals;
use Carbon\CarbonImmutable;

/**
 * A paid click keeps the network its address belongs to — its ASN, never
 * the address — and is flagged when that is a hosting, cloud or VPN
 * provider's network; one through iCloud Private Relay is a person behind
 * Apple's relay and is not flagged, whatever network carries it. Without the
 * networks' database nothing is kept and nothing is flagged.
 */
beforeEach(function () {
    CarbonImmutable::setTestNow('2026-11-17 12:00:00');
    geoDirectory();
    recordTraffic();
    $this->source = TrafficSource::factory()->create(['link_key' => 'exo']);
});

afterEach(function () {
    CarbonImmutable::setTestNow();
    removeGeoDirectory($this);
});

function clickFrom(string $address, string $clickId): TrafficVisit
{
    test()->withServerVariables(['REMOTE_ADDR' => $address])
        ->withHeaders(['User-Agent' => DESKTOP_CHROME_USER_AGENT, 'Accept-Language' => 'en-US,en;q=0.9', 'Sec-CH-UA' => '"Chromium";v="131"'])
        ->get('/?rg_src=exo&cid='.$clickId)
        ->assertOk();
    app()->forgetScopedInstances();

    return TrafficVisit::query()->latest('id')->firstOrFail();
}

it('keeps a click’s network, flags a hosting one, and not one behind Private Relay or a home connection', function () {
    installGeoDatabase('dbip_asn_lite', 'GeoLite2-ASN-Test.mmdb');
    installNetworkLists([1221], ['1.128.0.0/30']);

    $hosted = clickFrom('1.128.0.9', 'tag-1');
    $relay = clickFrom('1.128.0.2', 'tag-2');
    $home = clickFrom('12.81.92.1', 'tag-3');

    expect([$hosted->asn, ($hosted->bot_flags & BotSignals::DATACENTER) !== 0])->toBe([1221, true])
        ->and([$relay->asn, ($relay->bot_flags & BotSignals::DATACENTER) !== 0])->toBe([1221, false])
        ->and([$home->asn, ($home->bot_flags & BotSignals::DATACENTER) !== 0])->toBe([7018, false])
        ->and(BotSignals::words($hosted->bot_flags))->toBe(['Datacenter or VPN network']);
});

it('keeps no network and flags nothing while the networks’ database is missing', function () {
    installNetworkLists([1221]);

    $click = clickFrom('1.128.0.9', 'tag-4');

    expect($click->asn)->toBeNull()
        ->and($click->bot_flags & BotSignals::DATACENTER)->toBe(0);
});
