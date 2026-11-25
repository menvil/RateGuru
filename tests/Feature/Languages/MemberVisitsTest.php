<?php

use App\Actions\Profile\AnonymizeUserAccountAction;
use App\Actions\Users\TouchMemberVisitAction;
use App\Models\User;
use Carbon\CarbonImmutable;

/*
 * When a member was last seen, and where their previous visit ended — a gap of more than an hour starts a new one:
 * the feed counts a post in their language as fresh from there. Kept on the account, touched at most every few
 * minutes a session.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-25 12:00:00'));
});

it('marks a member seen on their first page, with no previous visit yet', function () {
    $member = User::factory()->create();

    $this->actingAs($member)->get('/')->assertOk();

    expect($member->fresh()->last_seen_at->toDateTimeString())->toBe('2026-11-25 12:00:00')
        ->and($member->fresh()->previous_visit_at)->toBeNull();
});

it('writes nothing again within a few minutes of the same session', function () {
    $member = User::factory()->create();
    $this->actingAs($member)->get('/');

    $this->travel(2)->minutes();
    $this->actingAs($member)->get('/');

    expect($member->fresh()->last_seen_at->toDateTimeString())->toBe('2026-11-25 12:00:00');
});

it('moves on the moment a member is seen, the visit going on within the hour', function () {
    $member = User::factory()->create();
    $this->actingAs($member)->get('/');

    $this->travel(10)->minutes();
    $this->actingAs($member)->get('/');

    expect($member->fresh()->last_seen_at->toDateTimeString())->toBe('2026-11-25 12:10:00')
        ->and($member->fresh()->previous_visit_at)->toBeNull();
});

it('starts a new visit after more than an hour away, keeping where the last one ended', function () {
    $member = User::factory()->create();
    $this->actingAs($member)->get('/');

    $this->travel(3)->days();
    $this->actingAs($member)->get('/');

    expect($member->fresh()->previous_visit_at->toDateTimeString())->toBe('2026-11-25 12:00:00')
        ->and($member->fresh()->last_seen_at->toDateTimeString())->toBe('2026-11-28 12:00:00');
});

it('starts a visit once when two requests race to start it', function () {
    $member = User::factory()->create();
    $member->forceFill(['last_seen_at' => now()->subDays(2)])->save();
    $first = $member->fresh();
    $second = $member->fresh();

    app(TouchMemberVisitAction::class)->handle($first, CarbonImmutable::now());
    app(TouchMemberVisitAction::class)->handle($second, CarbonImmutable::now()->addSecond());

    expect($member->fresh()->previous_visit_at->toDateTimeString())->toBe('2026-11-23 12:00:00')
        // The request that lost reads the visit the other one started.
        ->and($second->previous_visit_at->toDateTimeString())->toBe('2026-11-23 12:00:00')
        ->and($second->last_seen_at->toDateTimeString())->toBe('2026-11-25 12:00:00');
});

it('gives the rest of the request the visit it is on', function () {
    $member = User::factory()->create();
    $member->forceFill(['last_seen_at' => now()->subDays(2)])->save();
    $member = $member->fresh();

    app(TouchMemberVisitAction::class)->handle($member, CarbonImmutable::now());

    expect($member->previous_visit_at->toDateTimeString())->toBe('2026-11-23 12:00:00')
        ->and($member->isDirty())->toBeFalse();
});

it('marks nobody for a guest', function () {
    $this->get('/')->assertOk();

    expect(User::query()->whereNotNull('last_seen_at')->exists())->toBeFalse();
});

it('forgets when an anonymised account was last seen', function () {
    $member = User::factory()->create();
    $member->forceFill(['last_seen_at' => now(), 'previous_visit_at' => now()->subDay()])->save();

    app(AnonymizeUserAccountAction::class)->execute($member);

    expect($member->fresh()->last_seen_at)->toBeNull()
        ->and($member->fresh()->previous_visit_at)->toBeNull();
});
