<?php

use App\Enums\TrafficEvent;
use App\Enums\TrafficPostbackOutcome;
use App\Enums\TrafficPostbackSkip;
use App\Enums\TrafficPostbackStatus;
use App\Support\Traffic\Postbacks\PostbackPlanner;
use App\Support\Traffic\Postbacks\PostbackResponse;
use App\Support\Traffic\Postbacks\PostbackSchedule;
use App\Support\Traffic\Postbacks\PostbackUrl;
use Carbon\CarbonImmutable;

const EXO_RULES = ['rules' => [
    ['outcome' => 'ok', 'match' => 'equals', 'text' => 'OK'],
    ['outcome' => 'duplicate', 'match' => 'contains', 'text' => 'already been notified'],
    ['outcome' => 'rejected', 'match' => 'contains', 'text' => 'Tag is invalid'],
], 'otherwise' => 'retry'];

it('fills in an address, each value encoded, the click ID left for the moment of sending', function () {
    $url = PostbackUrl::render('https://pb.example.com/c?id={click_id}&v={value}&g={game}&z={param:zone}&x={param:none}', ['value' => '0.05', 'game' => 'trust your taste', 'param:zone' => 'a&b']);

    expect($url)->toBe('https://pb.example.com/c?id={click_id}&v=0.05&g=trust%20your%20taste&z=a%26b&x=')
        ->and(PostbackUrl::withClickId($url, 'ab/c'))->toBe('https://pb.example.com/c?id=ab%2Fc&v=0.05&g=trust%20your%20taste&z=a%26b&x=');
});

it('says why a conversion is not told to its network, or until when it is tried', function () {
    $arrived = CarbonImmutable::parse('2026-11-10 12:00:00');
    $holds = $arrived->addDays(2);
    $plan = fn (...$changes) => PostbackPlanner::plan(...[
        'event' => TrafficEvent::Started, 'known' => false, 'sourceActive' => true, 'hasClickId' => true, 'arrivedAt' => $arrived, 'holdsUntil' => $holds,
        'clickIdTtlHours' => 48, 'accountGraceDays' => 7, 'ruleIds' => [4, 5], 'occurredAt' => $arrived->addHour(), 'now' => $arrived->addHour(), ...$changes,
    ]);

    expect($plan()['rules'])->toBe([4, 5])
        ->and($plan()['expires_at']->toDateTimeString())->toBe('2026-11-11 13:00:00')
        ->and($plan(now: $arrived->addHours(40))['expires_at']->toDateTimeString())->toBe('2026-11-12 12:00:00')
        ->and($plan(known: true)['skip'])->toBe(TrafficPostbackSkip::KnownVisitor)
        ->and($plan(sourceActive: false)['skip'])->toBe(TrafficPostbackSkip::SourceInactive)
        ->and($plan(hasClickId: false)['skip'])->toBe(TrafficPostbackSkip::NoClickId)
        ->and($plan(occurredAt: $holds->addMinute())['skip'])->toBe(TrafficPostbackSkip::WindowClosed)
        ->and($plan(event: TrafficEvent::Account, clickIdTtlHours: null, occurredAt: $holds->addDays(3))['skip'])->toBeNull()
        ->and($plan(occurredAt: $arrived->addHours(47), clickIdTtlHours: 24)['skip'])->toBe(TrafficPostbackSkip::ClickIdExpired)
        ->and($plan(ruleIds: [])['skip'])->toBe(TrafficPostbackSkip::NoRule);
});

it('reads what a network’s answer means', function (int $status, string $body, ?array $rules, TrafficPostbackOutcome $outcome) {
    expect(PostbackResponse::outcome($status, $body, $rules))->toBe($outcome);
})->with([
    'any 2xx, no rules' => [204, '', null, TrafficPostbackOutcome::Ok],
    'ExoClick OK' => [200, " ok\n", EXO_RULES, TrafficPostbackOutcome::Ok],
    'ExoClick had it' => [200, 'Conversion has already been notified', EXO_RULES, TrafficPostbackOutcome::Duplicate],
    'ExoClick refused' => [200, 'ERROR: Tag is invalid', EXO_RULES, TrafficPostbackOutcome::Rejected],
    'ExoClick says nothing known' => [200, 'Something else', EXO_RULES, TrafficPostbackOutcome::HttpError],
    'refused' => [404, '', null, TrafficPostbackOutcome::Rejected],
    'too many' => [429, '', null, TrafficPostbackOutcome::HttpError],
    'timed out' => [408, '', null, TrafficPostbackOutcome::HttpError],
    'a redirect' => [302, '', null, TrafficPostbackOutcome::HttpError],
    'down' => [503, '', null, TrafficPostbackOutcome::HttpError],
]);

it('tries again after 1, 5 and 30 minutes, 2 and 9 hours, then gives up', function () {
    $now = CarbonImmutable::parse('2026-11-17 12:00:00');
    $retry = fn (int $attempts, ?CarbonImmutable $expires = null) => PostbackSchedule::after(TrafficPostbackOutcome::HttpError, $attempts, $expires, $now);

    expect(array_map(fn (int $attempt) => $retry($attempt)['run_after']->diffInSeconds($now, true), [1, 2, 3, 4, 5]))->toBe([60.0, 300.0, 1800.0, 7200.0, 32400.0])
        ->and($retry(6)['status'])->toBe(TrafficPostbackStatus::Failed)
        ->and($retry(5, $now->addHour())['status'])->toBe(TrafficPostbackStatus::Expired)
        ->and(PostbackSchedule::after(TrafficPostbackOutcome::Duplicate, 1, null, $now)['status'])->toBe(TrafficPostbackStatus::Delivered)
        ->and(PostbackSchedule::after(TrafficPostbackOutcome::Rejected, 1, null, $now)['status'])->toBe(TrafficPostbackStatus::Failed)
        ->and(PostbackResponse::excerpt("line\none\x07".str_repeat('x', 400)))->toHaveLength(255);
});

it('reads an error a network names only in a header', function () {
    $popads = ['rules' => [['outcome' => 'rejected', 'match' => 'header', 'text' => 'X-PopAds-EC']], 'otherwise' => 'ok'];

    expect(PostbackResponse::outcome(200, '', $popads, ['x-popads-ec' => '4']))->toBe(TrafficPostbackOutcome::Rejected)
        ->and(PostbackResponse::outcome(200, '', $popads, ['x-popads-ec' => ' ']))->toBe(TrafficPostbackOutcome::Ok)
        ->and(PostbackResponse::outcome(200, '', $popads))->toBe(TrafficPostbackOutcome::Ok);
});

it('keeps an answer that is not text as its size, so the log can always hold it', function () {
    $pixel = base64_decode('R0lGODlhAQABAIAAAP///wAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==');

    expect(PostbackResponse::excerpt($pixel))->toBe('[not text: 43 bytes]')
        ->and(PostbackResponse::excerpt("caf\xE9 ok"))->toBe('[not text: 7 bytes]')
        ->and(PostbackResponse::excerpt($pixel, 'X-PopAds-EC: 4 '))->toBe('X-PopAds-EC: 4 [not text: 43 bytes]')
        ->and(PostbackResponse::excerpt("OK\n"))->toBe('OK');
});
