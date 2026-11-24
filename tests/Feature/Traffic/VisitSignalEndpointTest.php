<?php

use App\Enums\AuthTrigger;
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

it('holds an address without the visitor cookie to 120 signals a minute, and a browser with one to 60 of its own', function () {
    foreach (range(1, 120) as $ignored) {
        $this->post(route('traffic.signal'), ['signal' => 'signup_form'])->assertNoContent();
    }

    $this->post(route('traffic.signal'), ['signal' => 'signup_form'])->assertStatus(429);

    // Browsers behind that address are known by their cookies: each has its own minute.
    foreach (range(1, 60) as $ignored) {
        signal(['signal' => 'signup_form'])->assertNoContent();
    }

    signal(['signal' => 'signup_form'])->assertStatus(429);
    signal(['signal' => 'signup_form'], 'otherBrowserotherBrowserotherBrowserothe')->assertNoContent();
});

it('records what opened the sign-in dialog once a visit, and the registration form only when it shows it', function () {
    $visit = analyticsVisit('signal-reader', ['visitor_hash' => browserHashOf(SIGNAL_TOKEN), 'network_day' => '2026-11-17', 'arrived_at' => '2026-11-17 11:50:00', 'last_seen_at' => '2026-11-17 11:55:00']);

    signal(['signal' => 'signup_form', 'mode' => 'login', 'trigger' => 'header_login'])->assertNoContent();

    expect($visit->fresh())->auth_opened_at->not->toBeNull()->auth_trigger->toBe(AuthTrigger::HeaderLogin)->register_form_at->toBeNull();

    // Switched to signing up — or opened again from a vote: the first opener stays.
    CarbonImmutable::setTestNow('2026-11-17 12:01:00');
    signal(['signal' => 'signup_form', 'mode' => 'register', 'trigger' => 'vote'])->assertNoContent();

    expect($visit->fresh())->auth_trigger->toBe(AuthTrigger::HeaderLogin)->register_form_at->toEqual(CarbonImmutable::parse('2026-11-17 12:01:00'));
});

it('records no step of the dialog for a signal that names no opener, and an opener it does not know as anything else', function () {
    $visit = analyticsVisit('signal-reader', ['visitor_hash' => browserHashOf(SIGNAL_TOKEN), 'network_day' => '2026-11-17', 'arrived_at' => '2026-11-17 11:50:00', 'last_seen_at' => '2026-11-17 11:55:00']);

    signal(['signal' => 'signup_form'])->assertNoContent();

    expect($visit->fresh()->auth_opened_at)->toBeNull();

    signal(['signal' => 'signup_form', 'mode' => 'register', 'trigger' => 'a_button_to_come'])->assertNoContent();

    expect($visit->fresh())->auth_trigger->toBe(AuthTrigger::Other)->register_form_at->not->toBeNull();
});

it('refuses a dialog state it does not know', function () {
    signal(['signal' => 'signup_form', 'mode' => 'forgot', 'trigger' => 'header_login'])->assertSessionHasErrors('mode');
});
