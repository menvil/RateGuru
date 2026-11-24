<?php

use App\Actions\Traffic\MarkTrafficVisitAction;
use App\Actions\Traffic\RecordRegistrationStepAction;
use App\Enums\AuthTrigger;
use App\Enums\SignupRefusal;
use App\Enums\TrafficEvent;
use App\Models\AnalyticsDirtyDay;
use App\Models\TrafficConversion;
use App\Models\TrafficPostback;
use App\Models\TrafficPostbackRule;
use App\Models\TrafficVisit;
use App\Support\Traffic\Capture\BotSignals;
use Carbon\CarbonImmutable;

/**
 * The registration steps between the sign-in dialog and an account, each put
 * once on the visit the browser is on — the paid click holding it, else the
 * visit it was seen on last within the session — while visits are recorded
 * and the visit is no crawler's: counted only, never a conversion, never a
 * postback, never a change of worth or of when a paid click settles.
 */
beforeEach(function () {
    CarbonImmutable::setTestNow('2026-11-17 12:00:00');
    recordTraffic();
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

function registrationSteps(): RecordRegistrationStepAction
{
    return app(RecordRegistrationStepAction::class);
}

it('puts each step on the paid click holding the browser, marking its day when it is days old', function () {
    $clicked = CarbonImmutable::parse('2026-11-15 09:00:00');
    $visit = TrafficVisit::factory()->create(['visitor_hash' => hash('sha256', 'paid'), 'arrived_at' => $clicked, 'last_seen_at' => $clicked, 'network_day' => '2026-11-15', 'holds_until' => now()->addDay()]);

    registrationSteps()->dialogShown(hash('sha256', 'paid'), true, AuthTrigger::Vote, CarbonImmutable::now());
    registrationSteps()->socialStarted(hash('sha256', 'paid'), 'google', CarbonImmutable::now());

    expect($visit->fresh())
        ->auth_opened_at->toEqual(now())
        ->auth_trigger->toBe(AuthTrigger::Vote)
        ->register_form_at->toEqual(now())
        ->social_started_at->toEqual(now())
        ->social_provider->toBe('google')
        ->and(AnalyticsDirtyDay::query()->pluck('day')->map(fn (mixed $day): string => substr((string) $day, 0, 10))->all())->toContain('2026-11-15');
});

it('puts each step on the unpaid visit the browser was seen on last within the session, and nothing past it', function () {
    $recent = analyticsVisit('recent', ['network_day' => '2026-11-17', 'arrived_at' => '2026-11-17 11:40:00', 'last_seen_at' => '2026-11-17 11:50:00']);
    $gone = analyticsVisit('gone', ['network_day' => '2026-11-17', 'arrived_at' => '2026-11-17 11:00:00', 'last_seen_at' => '2026-11-17 11:20:00']);

    registrationSteps()->submitted(hash('sha256', 'recent'), CarbonImmutable::now());
    registrationSteps()->submitted(hash('sha256', 'gone'), CarbonImmutable::now());

    expect($recent->fresh()->signup_submitted_at)->toEqual(now())
        ->and($recent->fresh()->last_seen_at)->toEqual(CarbonImmutable::parse('2026-11-17 11:50:00'))
        ->and($gone->fresh()->signup_submitted_at)->toBeNull();
});

it('puts a step on the paid click holding the browser over a newer unpaid visit, and on the latest of two unpaid visits of the session', function () {
    $hash = hash('sha256', 'busy');
    $click = TrafficVisit::factory()->create(['visitor_hash' => $hash, 'holds_until' => now()->addDay(), 'last_seen_at' => now()->subMinutes(20)]);
    $unpaid = analyticsVisit('busy', ['network_day' => '2026-11-17', 'arrived_at' => '2026-11-17 11:50:00', 'last_seen_at' => '2026-11-17 11:58:00']);

    registrationSteps()->submitted($hash, CarbonImmutable::now());

    expect($click->fresh()->signup_submitted_at)->not->toBeNull()
        ->and($unpaid->fresh()->signup_submitted_at)->toBeNull();

    $other = hash('sha256', 'reader');
    $earlier = analyticsVisit('reader', ['network_day' => '2026-11-17', 'arrived_at' => '2026-11-17 11:40:00', 'last_seen_at' => '2026-11-17 11:45:00']);
    $latest = analyticsVisit('reader', ['network_day' => '2026-11-17', 'arrived_at' => '2026-11-17 11:50:00', 'last_seen_at' => '2026-11-17 11:55:00']);

    registrationSteps()->submitted($other, CarbonImmutable::now());

    expect($latest->fresh()->signup_submitted_at)->not->toBeNull()
        ->and($earlier->fresh()->signup_submitted_at)->toBeNull();
});

it('writes the steps through the one writer of a visit’s marks, under its lock', function () {
    $visit = analyticsVisit('reader', ['network_day' => '2026-11-17', 'arrived_at' => '2026-11-17 11:55:00', 'last_seen_at' => '2026-11-17 11:55:00']);

    expect(app(MarkTrafficVisitAction::class)->registrationSteps($visit->id, ['signup_submitted_at' => []], SignupRefusal::Invalid->bit(), CarbonImmutable::now()))->toBeTrue()
        ->and(app(MarkTrafficVisitAction::class)->registrationSteps($visit->id, ['signup_submitted_at' => []], SignupRefusal::Invalid->bit(), CarbonImmutable::now()))->toBeFalse()
        ->and($visit->fresh())->signup_submitted_at->not->toBeNull()->signup_refusals->toBe(SignupRefusal::Invalid->bit());
});

it('records nothing while visits aren’t recorded, on a crawler’s visit, or for a browser without a visit', function () {
    $visit = analyticsVisit('reader', ['network_day' => '2026-11-17', 'arrived_at' => '2026-11-17 11:55:00', 'last_seen_at' => '2026-11-17 11:55:00']);
    $crawler = analyticsVisit('crawler', ['network_day' => '2026-11-17', 'arrived_at' => '2026-11-17 11:55:00', 'last_seen_at' => '2026-11-17 11:55:00', 'bot_flags' => BotSignals::CRAWLER]);

    recordTraffic(capture: false);
    registrationSteps()->dialogShown(hash('sha256', 'reader'), true, AuthTrigger::HeaderSignup, CarbonImmutable::now());
    recordTraffic();
    registrationSteps()->dialogShown(hash('sha256', 'crawler'), true, AuthTrigger::HeaderSignup, CarbonImmutable::now());
    registrationSteps()->dialogShown(hash('sha256', 'nobody'), true, AuthTrigger::HeaderSignup, CarbonImmutable::now());
    registrationSteps()->submitted(null, CarbonImmutable::now());

    expect($visit->fresh()->auth_opened_at)->toBeNull()
        ->and($crawler->fresh()->auth_opened_at)->toBeNull();
});

it('keeps the first moment and the first opener of the dialog, and every reason a sign-up was refused', function () {
    $visit = analyticsVisit('reader', ['network_day' => '2026-11-17', 'arrived_at' => '2026-11-17 11:55:00', 'last_seen_at' => '2026-11-17 11:55:00']);
    $hash = hash('sha256', 'reader');
    $at = fn (string $time): CarbonImmutable => CarbonImmutable::parse('2026-11-17 '.$time);

    // Opened to log in; switched to sign up later, from another button: the first opener stays.
    registrationSteps()->dialogShown($hash, false, AuthTrigger::HeaderLogin, $at('12:00:00'));
    expect($visit->fresh())->auth_opened_at->toEqual($at('12:00:00'))->register_form_at->toBeNull();

    registrationSteps()->dialogShown($hash, true, AuthTrigger::Vote, $at('12:01:00'));
    registrationSteps()->refused($hash, [SignupRefusal::Captcha], $at('12:02:00'));
    registrationSteps()->refused($hash, [SignupRefusal::EmailTaken, SignupRefusal::Captcha], $at('12:03:00'));
    registrationSteps()->socialStarted($hash, 'google', $at('12:04:00'));
    registrationSteps()->socialStarted($hash, 'facebook', $at('12:05:00'));

    expect($visit->fresh())
        ->auth_trigger->toBe(AuthTrigger::HeaderLogin)
        ->register_form_at->toEqual($at('12:01:00'))
        // A refused sign-up was a sent one.
        ->signup_submitted_at->toEqual($at('12:02:00'))
        ->signup_refused_at->toEqual($at('12:02:00'))
        ->signup_refusals->toBe(SignupRefusal::mask([SignupRefusal::EmailTaken, SignupRefusal::Captcha]))
        ->social_started_at->toEqual($at('12:04:00'))
        ->social_provider->toBe('google');
});

it('marks a game’s own sign-up form on the game’s visit, opened by the game’s form', function () {
    $visit = analyticsVisit('player', ['network_day' => '2026-11-17']);

    registrationSteps()->formShownOn($visit->id, CarbonImmutable::now());
    registrationSteps()->formShownOn($visit->id, CarbonImmutable::now()->addMinute());

    expect($visit->fresh())
        ->auth_opened_at->toEqual(now())
        ->auth_trigger->toBe(AuthTrigger::GameForm)
        ->register_form_at->toEqual(now());
});

it('never makes a conversion or a postback, never revalues, never keeps a paid click from settling', function () {
    recordTraffic(postbacks: true);
    $visit = TrafficVisit::factory()->create(['visitor_hash' => hash('sha256', 'paid'), 'click_hash' => hash('sha256', 'click'), 'holds_until' => now()->addDay(), 'last_seen_at' => now()->subMinutes(10)]);

    foreach (TrafficEvent::postbackEvents() as $event) {
        TrafficPostbackRule::factory()->create(['source_id' => $visit->source_id, 'event' => $event, 'enabled' => true]);
    }

    $before = $visit->fresh();
    $hash = hash('sha256', 'paid');
    registrationSteps()->dialogShown($hash, true, AuthTrigger::HeaderSignup, CarbonImmutable::now());
    registrationSteps()->socialStarted($hash, 'facebook', CarbonImmutable::now());
    registrationSteps()->submitted($hash, CarbonImmutable::now());
    registrationSteps()->refused($hash, [SignupRefusal::Throttled], CarbonImmutable::now());
    $after = $visit->fresh();

    expect(TrafficConversion::query()->count())->toBe(0)
        ->and(TrafficPostback::query()->count())->toBe(0)
        ->and([$after->value_real, $after->value_expected, $after->last_seen_at?->toDateTimeString(), $after->settled_at])
        ->toBe([$before->value_real, $before->value_expected, $before->last_seen_at?->toDateTimeString(), $before->settled_at])
        ->and($after->signup_refusals)->toBe(SignupRefusal::Throttled->bit());
});
