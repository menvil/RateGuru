<?php

use App\Models\TrafficVisit;
use App\Support\Traffic\Capture\ArrivalToken;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;

/**
 * What the small script of a page other than a game tells the tracker: that
 * the page ran, and that a sign-up form was shown. It always answers 204.
 */
const SIGNAL_TOKEN = 'signalTokensignalTokensignalTokensignalT';

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-11-17 12:00:00');
    recordTraffic();
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

function signal(array $data, string $token = SIGNAL_TOKEN): TestResponse
{
    return test()->withCookie((string) config('games.visitor_cookie.name'), $token)
        ->withHeaders(['User-Agent' => DESKTOP_CHROME_USER_AGENT, 'Accept-Language' => 'en'])
        ->post(route('traffic.signal'), $data);
}

it('marks a paid click opened when its page ran', function () {
    $visit = TrafficVisit::factory()->create(['visitor_hash' => browserHashOf(SIGNAL_TOKEN)]);

    signal(['signal' => 'opened', 'arrival' => ArrivalToken::make(['visit' => $visit->id], browserHashOf(SIGNAL_TOKEN), now()->getTimestamp())])->assertNoContent();

    expect($visit->fresh()->opened_at)->not->toBeNull();
});

it('marks once that a sign-up form was shown, on the click holding the browser', function () {
    $visit = TrafficVisit::factory()->create(['visitor_hash' => browserHashOf(SIGNAL_TOKEN)]);

    signal(['signal' => 'signup_form'])->assertNoContent();
    $first = $visit->fresh()->signup_form_at;
    CarbonImmutable::setTestNow('2026-11-17 12:05:00');
    signal(['signal' => 'signup_form'])->assertNoContent();

    expect($first)->not->toBeNull()->and($visit->fresh()->signup_form_at->equalTo($first))->toBeTrue();
});

it('answers 204 to a browser without the cookie, a forged token, and a stale one, recording nothing', function () {
    $visit = TrafficVisit::factory()->create(['visitor_hash' => browserHashOf(SIGNAL_TOKEN)]);

    $this->post(route('traffic.signal'), ['signal' => 'opened', 'arrival' => 'nonsense'])->assertNoContent();
    signal(['signal' => 'opened', 'arrival' => 'nonsense'])->assertNoContent();
    signal(['signal' => 'opened', 'arrival' => ArrivalToken::make(['visit' => $visit->id], browserHashOf(SIGNAL_TOKEN), now()->subHours(3)->getTimestamp())])->assertNoContent();

    expect($visit->fresh()->opened_at)->toBeNull();
});

it('refuses a signal it does not know', function () {
    signal(['signal' => 'bought'])->assertSessionHasErrors('signal');
});

it('holds a browser to 30 signals a minute', function () {
    foreach (range(1, 30) as $ignored) {
        signal(['signal' => 'signup_form'])->assertNoContent();
    }

    signal(['signal' => 'signup_form'])->assertStatus(429);
});
