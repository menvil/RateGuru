<?php

use App\Enums\TrafficChannel;
use App\Enums\TrafficDevice;
use App\Support\Traffic\Capture\BotSignals;
use App\Support\Traffic\Capture\ChannelClassifier;
use App\Support\Traffic\Capture\ClickParameters;
use App\Support\Traffic\Capture\DeviceClassifier;
use App\Support\Traffic\Links\LinkTokenDetector;

const CAPTURE_LINK_PARAMS = [
    ['kind' => 'click', 'param' => 'cid'],
    ['kind' => 'campaign', 'param' => 'campaign'],
    ['kind' => 'site', 'param' => 'site'],
    ['kind' => 'zone', 'param' => 'zone'],
    ['kind' => 'cost', 'param' => 'cost'],
];

it('tells every network\'s placeholder from a real value', function (string $value, bool $token) {
    expect(LinkTokenDetector::isToken($value))->toBe($token);
})->with([
    ['{click_id}', true], ['[IMPRESSIONID]', true], ['##SUB_ID_SHORT(action)##', true], ['${SUBID}', true],
    ['%7Bconversions_tracking%7D', true], ['abc123', false], ['12{3}', false], ['', false],
]);

it('reads a paid click by the source\'s own parameters, and keeps the other ones', function () {
    $click = ClickParameters::parse(['rg_src' => 'exo', 'cid' => 'tag-1', 'campaign' => '5521904', 'site' => '40123', 'zone' => '44718', 'cost' => '0.00045', 'utm_term' => 'pop', 'lang' => 'en'], CAPTURE_LINK_PARAMS);

    expect([$click->clickId, $click->campaign, $click->site, $click->zone, $click->cost])->toBe(['tag-1', '5521904', '40123', '44718', '0.00045'])
        ->and($click->extra)->toBe(['utm_term' => 'pop', 'lang' => 'en']);
});

it('counts an unfilled placeholder, a non-string, an overlong value and a price that is no number as no value', function () {
    $click = ClickParameters::parse(['cid' => '{conversions_tracking}', 'campaign' => ['5521904'], 'site' => str_repeat('s', 121), 'zone' => " \t", 'cost' => '1e5'], CAPTURE_LINK_PARAMS);

    expect([$click->clickId, $click->campaign, $click->site, $click->zone, $click->cost])->toBe([null, null, null, null, null]);
});

it('takes a banner\'s creative from rg_cr', function () {
    expect(ClickParameters::parse(['rg_src' => 'banner', 'rg_cr' => 'main'], [])->creative)->toBe('main');
});

it('tells mobile from desktop, a tablet counting as mobile', function (?string $agent, ?string $mobile, ?string $platform, TrafficDevice $device) {
    expect(DeviceClassifier::classify($agent, $mobile, $platform))->toBe($device);
})->with([
    'a phone by its hint' => [null, '?1', '"Android"', TrafficDevice::Mobile],
    'a tablet that says it is no phone' => [null, '?0', '"Android"', TrafficDevice::Mobile],
    'a desktop by its hint' => [null, '?0', '"Windows"', TrafficDevice::Desktop],
    'an iPhone by its agent' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148', null, null, TrafficDevice::Mobile],
    'an iPad by its agent' => ['Mozilla/5.0 (iPad; CPU OS 18_0 like Mac OS X)', null, null, TrafficDevice::Mobile],
    'a desktop by its agent' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/131.0 Safari/537.36', null, null, TrafficDevice::Desktop],
    'nothing at all' => [null, null, null, TrafficDevice::Desktop],
]);

it('marks the cheap signs that a hit is no person', function () {
    $browser = ['accept-language' => 'en-GB', 'sec-ch-ua' => '"Chromium";v="131"'];
    $chrome = 'Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 Chrome/131.0 Safari/537.36';

    expect(BotSignals::of($chrome, $browser))->toBe(0)
        ->and(BotSignals::of('facebookexternalhit/1.1', $browser) & BotSignals::CRAWLER)->toBe(BotSignals::CRAWLER)
        ->and(BotSignals::of($chrome, [...$browser, 'sec-purpose' => 'prefetch;prerender']) & BotSignals::PREFETCH)->toBe(BotSignals::PREFETCH)
        ->and(BotSignals::of('Mozilla/5.0 HeadlessChrome/131.0', $browser) & BotSignals::HEADLESS)->toBe(BotSignals::HEADLESS)
        ->and(BotSignals::of($chrome, ['accept-language' => 'en']) & BotSignals::NO_BROWSER_HEADERS)->toBe(BotSignals::NO_BROWSER_HEADERS)
        ->and(BotSignals::of(null, []) & BotSignals::NO_BROWSER_HEADERS)->toBe(BotSignals::NO_BROWSER_HEADERS)
        // A phone whose brand ends in "bot" is a person; a crawler's name ending in "bot" is not.
        ->and(BotSignals::of('Mozilla/5.0 (Linux; Android 11; CUBOT P50) AppleWebKit/537.36 Chrome/120.0 Mobile Safari/537.36', $browser))->toBe(0)
        ->and(BotSignals::of('Mozilla/5.0 (compatible; Googlebot/2.1)', $browser) & BotSignals::CRAWLER)->toBe(BotSignals::CRAWLER)
        ->and(BotSignals::of('Mozilla/5.0 (compatible; AhrefsBot/7.0)', $browser) & BotSignals::CRAWLER)->toBe(BotSignals::CRAWLER);
});

it('finds the unpaid channel a visit came through', function (?string $referrer, bool $result, ?string $route, ?string $agent, TrafficChannel $channel, ?string $origin) {
    expect(ChannelClassifier::classify($referrer, 'rateguru.test', $result, $route, $agent))->toBe(['channel' => $channel, 'origin' => $origin]);
})->with([
    'nothing' => [null, false, null, null, TrafficChannel::Direct, null],
    'a result link opened from a message' => [null, true, null, null, TrafficChannel::Shared, null],
    'a result page reached from a social network' => ['https://l.facebook.com/l.php?u=x', true, null, null, TrafficChannel::Shared, 'facebook.com'],
    'a game reached from a result page' => ['https://rateguru.test/games/taste/v1/expert', false, 'games.result', null, TrafficChannel::Shared, 'result page'],
    'the feed' => ['https://www.rateguru.test/', false, 'feed', null, TrafficChannel::Internal, 'feed'],
    'a post' => ['https://rateguru.test/posts/12', false, 'posts.show', null, TrafficChannel::Internal, 'post'],
    'a search engine' => ['https://www.google.com/', false, null, null, TrafficChannel::Search, 'google'],
    'a search app' => ['android-app://com.google.android.googlequicksearchbox/', false, null, null, TrafficChannel::Search, 'google'],
    'another site' => ['https://forum.example.org/thread/1', false, null, null, TrafficChannel::Referral, 'forum.example.org'],
    'a redirector' => ['https://t.co/abc', false, null, null, TrafficChannel::Referral, 'twitter.com'],
    'an in-app browser' => [null, false, null, 'Mozilla/5.0 (iPhone) [FBAN/FBIOS;FBAV/450.0]', TrafficChannel::Referral, 'in-app browser'],
]);
