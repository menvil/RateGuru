<?php

use App\Queries\Analytics\RegistrationFunnelQuery;
use App\Support\Analytics\FunnelLanding;
use Carbon\CarbonImmutable;

/**
 * The registration funnel of a period's new visitors: the people with a
 * visit the filters and the landing admit, and what each did over the
 * period's visits — a step counting only the people of the steps before it,
 * Google or Facebook counted whether or not the registration form was seen —
 * with what opened the dialog, every reason a sign-up was refused, the people
 * of each step who viewed a post, and everyone who signed up by any way.
 */
beforeEach(function () {
    CarbonImmutable::setTestNow('2026-11-18 12:00:00');

    // The way through by email, from the header's Sign up, a post viewed on the way.
    $ann = analyticsVisit('ann', ['landing_key' => 'feed', 'auth_opened_at' => '2026-11-15 10:01:00', 'auth_trigger' => 'header_signup', 'register_form_at' => '2026-11-15 10:01:00', 'signup_submitted_at' => '2026-11-15 10:02:00', 'signup_at' => '2026-11-15 10:02:00', 'account_at' => '2026-11-15 11:00:00', 'activated_at' => '2026-11-16 09:00:00']);
    analyticsVisitPost($ann, 7, ['views' => 1]);
    // From a vote on the post landed on: the address was taken.
    analyticsVisit('bob', ['landing_key' => 'posts.show', 'landing_post_id' => 42, 'auth_opened_at' => '2026-11-15 10:01:00', 'auth_trigger' => 'vote', 'register_form_at' => '2026-11-15 10:01:00', 'signup_submitted_at' => '2026-11-15 10:02:00', 'signup_refused_at' => '2026-11-15 10:02:00', 'signup_refusals' => 1]);
    // Google from the sign-in form: no registration form seen, an account at once.
    analyticsVisit('cat', ['landing_key' => 'feed', 'auth_opened_at' => '2026-11-15 10:01:00', 'auth_trigger' => 'header_login', 'social_started_at' => '2026-11-15 10:01:30', 'social_provider' => 'google', 'signup_at' => '2026-11-15 10:02:00', 'account_at' => '2026-11-15 10:02:00']);
    // Landed and left.
    analyticsVisit('dan', ['landing_key' => 'feed']);
    // A member: no new visitor.
    analyticsVisit('eve', ['landing_key' => 'feed', 'known' => true, 'known_reason' => 'browser', 'auth_opened_at' => '2026-11-15 10:01:00', 'auth_trigger' => 'header_login']);
    // Landed on the post, signed up the next day on a game's own form.
    analyticsVisit('fay', ['landing_key' => 'posts.show', 'landing_post_id' => 42]);
    analyticsVisit('fay', ['network_day' => '2026-11-16', 'arrived_at' => '2026-11-16 10:00:00', 'landing_key' => 'games.play:3', 'auth_opened_at' => '2026-11-16 10:01:00', 'auth_trigger' => 'game_form', 'register_form_at' => '2026-11-16 10:01:00', 'signup_submitted_at' => '2026-11-16 10:02:00', 'signup_at' => '2026-11-16 10:02:00']);

    fillAnalyticsRollup();
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

it('counts each step among the people of the steps before it, Google or Facebook whether or not the registration form was seen', function () {
    $funnel = app(RegistrationFunnelQuery::class)->read(analyticsFilter('7d'), FunnelLanding::any());

    expect($funnel->people())->toBe([
        'landed' => 5, 'opened' => 4, 'register_form' => 3, 'started' => 4, 'sent_email' => 3, 'started_social' => 1,
        'signed_up' => 3, 'confirmed' => 2, 'activated' => 1,
    ])
        ->and($funnel->viewedPost())->toBe(['landed' => 1, 'opened' => 1, 'register_form' => 1, 'started' => 1, 'signed_up' => 1, 'confirmed' => 1, 'activated' => 1])
        ->and($funnel->signedUpAnyWay)->toBe(3)
        ->and($funnel->triggers)->toBe(['game_form' => 1, 'header_login' => 1, 'header_signup' => 1, 'vote' => 1])
        ->and($funnel->refusals)->toBe(['email_taken' => 1]);
});

it('takes the people who landed where asked, and what they did on any visit of the period', function (FunnelLanding $landing, array $people) {
    $funnel = app(RegistrationFunnelQuery::class)->read(analyticsFilter('7d'), $landing);

    expect(array_intersect_key($funnel->people(), $people))->toBe($people);
})->with([
    'the home feed' => [fn () => FunnelLanding::feed(), ['landed' => 3, 'opened' => 2, 'signed_up' => 2]],
    'any post' => [fn () => FunnelLanding::anyPost(), ['landed' => 2, 'opened' => 2, 'register_form' => 2, 'started' => 2, 'signed_up' => 1, 'confirmed' => 0]],
    'that post' => [fn () => FunnelLanding::post(42), ['landed' => 2, 'signed_up' => 1]],
    'another post' => [fn () => FunnelLanding::post(43), ['landed' => 0, 'opened' => 0]],
]);

it('narrows the people by the page’s filters, and counts what they did on a later visit from anywhere', function () {
    // Came from Reddit, signed up the next day on a visit of no source; someone else came from Google.
    analyticsVisit('gus', ['source_key' => 'reddit:organic', 'landing_key' => 'feed']);
    analyticsVisit('gus', ['network_day' => '2026-11-16', 'arrived_at' => '2026-11-16 10:00:00', 'landing_key' => 'feed', 'auth_opened_at' => '2026-11-16 10:01:00', 'auth_trigger' => 'header_signup', 'register_form_at' => '2026-11-16 10:01:00', 'signup_submitted_at' => '2026-11-16 10:02:00', 'signup_at' => '2026-11-16 10:02:00']);
    analyticsVisit('hal', ['source_key' => 'google:organic', 'landing_key' => 'feed', 'auth_opened_at' => '2026-11-15 10:01:00', 'auth_trigger' => 'vote']);
    fillAnalyticsRollup();

    $funnel = app(RegistrationFunnelQuery::class)->read(analyticsFilter('7d', source: 'reddit:organic'), FunnelLanding::any());

    expect(array_intersect_key($funnel->people(), array_flip(['landed', 'opened', 'signed_up'])))->toBe(['landed' => 1, 'opened' => 1, 'signed_up' => 1])
        ->and($funnel->triggers)->toBe(['header_signup' => 1]);
});

it('reads the funnel only from Analytics’ own rows, and says so while they aren’t ready', function () {
    config(['analytics.rollup.read' => false]);
    app()->forgetScopedInstances();

    expect(app(RegistrationFunnelQuery::class)->read(analyticsFilter('7d'), FunnelLanding::any()))->toBeNull();
});
