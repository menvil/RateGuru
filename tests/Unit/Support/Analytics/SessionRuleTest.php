<?php

use App\Enums\TrafficChannel;
use App\Support\Analytics\SessionRule;
use Carbon\CarbonImmutable;

/*
 * Whether an unpaid arrival continues the browser's session: a move inside
 * the site or a page opened with no referrer does, while the last unpaid
 * visit was seen less than the session's minutes ago; anything from outside,
 * a result page reached from anywhere, other tags and a paid click begin
 * their own. The same page from the same place again is the same visit.
 */

const SESSION_NOW = '2026-11-18 12:00:00';

function sessionContinues(TrafficChannel $channel, string $lastSeen = '2026-11-18 11:45:00', bool $onResultPage = false, bool $tagsDiffer = false): bool
{
    return SessionRule::continues($channel, $onResultPage, $tagsDiffer, CarbonImmutable::parse($lastSeen), CarbonImmutable::parse(SESSION_NOW), 30);
}

it('continues the session on a move inside the site and on a page opened with no referrer', function (TrafficChannel $channel) {
    expect(sessionContinues($channel))->toBeTrue();
})->with([TrafficChannel::Internal, TrafficChannel::Direct]);

it('begins a session of its own from another site or a search', function (TrafficChannel $channel) {
    expect(sessionContinues($channel))->toBeFalse();
})->with([TrafficChannel::Referral, TrafficChannel::Search]);

it('begins a session of its own on a result page, and continues it on a game reached from the site’s own result page', function () {
    expect(sessionContinues(TrafficChannel::Shared, onResultPage: true))->toBeFalse()
        ->and(sessionContinues(TrafficChannel::Shared, onResultPage: false))->toBeTrue();
});

it('never continues a session with a paid click, or with tags other than the session’s', function () {
    expect(sessionContinues(TrafficChannel::Network))->toBeFalse()
        ->and(sessionContinues(TrafficChannel::Placement))->toBeFalse()
        ->and(sessionContinues(TrafficChannel::Internal, tagsDiffer: true))->toBeFalse();
});

it('ends the session once the last unpaid visit was seen the session’s minutes ago', function (string $lastSeen, bool $continues) {
    expect(sessionContinues(TrafficChannel::Internal, $lastSeen))->toBe($continues)
        ->and(sessionContinues(TrafficChannel::Direct, $lastSeen))->toBe($continues);
})->with([
    'a second short of 30 minutes' => ['2026-11-18 11:30:01', true],
    'exactly 30 minutes' => ['2026-11-18 11:30:00', false],
    'more than 30 minutes' => ['2026-11-18 11:00:00', false],
]);

it('begins a session when the browser has no unpaid visit to continue, and reads the minutes it is given', function () {
    $now = CarbonImmutable::parse(SESSION_NOW);

    expect(SessionRule::continues(TrafficChannel::Internal, false, false, null, $now, 30))->toBeFalse()
        ->and(SessionRule::continues(TrafficChannel::Internal, false, false, $now->subMinutes(45), $now, 60))->toBeTrue()
        ->and(SessionRule::continues(TrafficChannel::Internal, false, false, $now->subMinutes(10), $now, 10))->toBeFalse();
});

it('takes the same page from the same place again within the minutes as the same visit', function () {
    $now = CarbonImmutable::parse(SESSION_NOW);

    expect(SessionRule::repeats('google:organic', 'google:organic', $now->subMinutes(5), $now, 30))->toBeTrue()
        ->and(SessionRule::repeats('reddit:organic', 'google:organic', $now->subMinutes(5), $now, 30))->toBeFalse()
        ->and(SessionRule::repeats('google:organic', null, $now->subMinutes(5), $now, 30))->toBeFalse()
        ->and(SessionRule::repeats('google:organic', 'google:organic', $now->subMinutes(30), $now, 30))->toBeFalse();
});
