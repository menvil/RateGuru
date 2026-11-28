<?php

use App\Models\TrafficSetting;
use App\Models\TrafficSource;
use App\Models\TrafficVisit;
use App\Support\Traffic\Capture\PresenceChecks;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * What a landing page measured of its visitor, while Traffic sources records
 * it: the page says so with <meta name="rg-presence"> only where a visitor
 * arrived; its report marks the paid click its token names — on screen, then
 * touched or clicked, each once — adds what contradicts what the browser says
 * it is, and keeps the facts on the click's details, a later report merged
 * over the first. Switched off, or for a crawler, nothing is recorded.
 */
const PRESENCE_BROWSER = 'presenceBrowserpresenceBrowserpresenceBr';

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-11-17 12:00:00');
    recordTraffic()->forceFill(['presence_enabled' => true])->save();
    TrafficSetting::forgetCaptureFlags();
    $this->source = TrafficSource::factory()->create(['link_key' => 'exo']);
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

/** The arrival token a landing page printed, and whether it asked to be measured. */
function landing(string $clickId): array
{
    $page = (string) clickOn('/?rg_src=exo&cid='.$clickId, PRESENCE_BROWSER)->assertOk()->getContent();
    preg_match('/<meta name="rg-arrival" content="([^"]+)">/', $page, $meta);

    return [html_entity_decode($meta[1] ?? ''), str_contains($page, '<meta name="rg-presence" content="1">')];
}

function reportPresence(?string $arrival, array $fields, string $agent = DESKTOP_CHROME_USER_AGENT): void
{
    test()->withCookie((string) config('games.visitor_cookie.name'), PRESENCE_BROWSER)
        ->withHeaders(['User-Agent' => $agent, 'Accept-Language' => 'en-GB', 'Sec-CH-UA' => '"Chromium";v="131"'])
        ->post(route('traffic.signal'), ['signal' => 'presence', ...($arrival === null ? [] : ['arrival' => $arrival]), ...$fields])
        ->assertNoContent();
}

it('asks an arriving page to measure its visitor, and marks its click on screen, then used, each once, with what contradicts the browser', function () {
    [$arrival, $measured] = landing('tag-1');
    $visit = TrafficVisit::query()->sole();

    expect($measured)->toBeTrue();

    // On screen three seconds after it loaded behind the window; nothing touched yet; a Linux machine calling itself Windows.
    reportPresence($arrival, ['vis0' => '0', 'tvis' => '41000', 'visms' => '3000', 'plat' => 'Linux x86_64', 'gl' => 'hw', 'sw' => '1920', 'sh' => '1080', 'ww' => '1280', 'wh' => '700', 'mtp' => '0']);
    $visit->refresh();

    expect($visit->seen_at?->toDateTimeString())->toBe('2026-11-17 12:00:00')
        ->and($visit->engaged_at)->toBeNull()
        ->and($visit->quality_flags)->toBe(PresenceChecks::PLATFORM)
        ->and($visit->detail->presence)->toMatchArray(['to_visible_ms' => 41000, 'platform' => 'Linux x86_64']);

    CarbonImmutable::setTestNow('2026-11-17 12:00:05');
    reportPresence($arrival, ['vis0' => '0', 'tvis' => '41000', 'visms' => '8000', 'tin' => '2200', 'inkind' => 'pointer', 'ins' => '2', 'unt' => '3', 'plat' => 'Win32']);
    $visit->refresh();

    expect($visit->seen_at?->toDateTimeString())->toBe('2026-11-17 12:00:00')
        ->and($visit->engaged_at?->toDateTimeString())->toBe('2026-11-17 12:00:05')
        // Scripted events beside the visitor's own say nothing.
        ->and($visit->quality_flags)->toBe(PresenceChecks::PLATFORM)
        ->and($visit->detail->presence)->toMatchArray(['to_input_ms' => 2200, 'inputs' => 2, 'visible_ms' => 8000, 'platform' => 'Linux x86_64']);
});

it('puts a report without a token on the browser’s visit seen last, never on an older paid click that only still holds it', function () {
    landing('tag-old');
    $click = TrafficVisit::query()->sole();
    CarbonImmutable::setTestNow('2026-11-19 09:00:00');
    $now = TrafficVisit::factory()->create(['visitor_hash' => browserHashOf(PRESENCE_BROWSER), 'source_id' => null, 'arrived_at' => now(), 'last_seen_at' => now(), 'network_day' => '2026-11-19']);

    reportPresence(null, ['vis0' => '1', 'tvis' => '300', 'tin' => '900', 'ins' => '1']);

    expect($click->fresh())->seen_at->toBeNull()->engaged_at->toBeNull()
        ->and($now->fresh())->seen_at->not->toBeNull()->engaged_at->not->toBeNull();
});

it('reads the switches whole again when the value cached before measuring came lacks it', function () {
    Cache::forever('traffic-capture-flags', ['capture' => true, 'site' => false]);

    expect(TrafficSetting::captureFlags())->toBe(['capture' => true, 'site' => false, 'presence' => true])
        ->and(landing('tag-cache')[1])->toBeTrue();
});

it('puts a report without a token on the visit the browser is on now', function () {
    landing('tag-2');

    reportPresence(null, ['vis0' => '1', 'tvis' => '300', 'tin' => '900', 'ins' => '1']);

    expect(TrafficVisit::query()->sole())->seen_at->not->toBeNull()->engaged_at->not->toBeNull();
});

it('neither asks a page to measure nor records a report while it is switched off, and never for a crawler', function () {
    TrafficSetting::current()->forceFill(['presence_enabled' => false])->save();
    TrafficSetting::forgetCaptureFlags();
    [$arrival, $measured] = landing('tag-3');

    reportPresence($arrival, ['vis0' => '1', 'tvis' => '300', 'wd' => '1']);

    expect($measured)->toBeFalse()
        ->and(TrafficVisit::query()->sole())->seen_at->toBeNull()->quality_flags->toBe(0);

    TrafficSetting::current()->forceFill(['presence_enabled' => true])->save();
    TrafficSetting::forgetCaptureFlags();
    reportPresence($arrival, ['vis0' => '1', 'tvis' => '300', 'wd' => '1'], 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)');

    expect(TrafficVisit::query()->sole())->seen_at->toBeNull()->quality_flags->toBe(0);
});

it('asks no page to measure where no visitor arrived', function () {
    $page = (string) clickOn('/', PRESENCE_BROWSER)->assertOk()->getContent();

    expect($page)->not->toContain('rg-presence');
});

it('refuses a report whose facts are too long, and records nothing for a browser without the tracker’s cookie', function () {
    [$arrival] = landing('tag-4');

    test()->withCookie((string) config('games.visitor_cookie.name'), PRESENCE_BROWSER)
        ->post(route('traffic.signal'), ['signal' => 'presence', 'arrival' => $arrival, 'tz' => str_repeat('x', 65)])
        ->assertSessionHasErrors('tz');

    // Cookies set for one request stay for the next: this browser sends none.
    $this->defaultCookies = [];
    $this->post(route('traffic.signal'), ['signal' => 'presence', 'arrival' => $arrival, 'vis0' => '1'])->assertNoContent();

    expect(TrafficVisit::query()->sole()->seen_at)->toBeNull();
});
