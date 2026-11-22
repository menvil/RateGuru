<?php

use App\Support\Analytics\AnalyticsRollupWindow;
use App\Support\Analytics\AnalyticsVisitRow;
use Carbon\CarbonImmutable;

/*
 * A visit as Growth → Analytics reads it: the browser the number a database
 * makes of the first 64 bits of its hash, each step a flag, the account's
 * sign-up only once it confirmed, every value the text an engine hands back,
 * so a row written before compares equal to the one the same visit makes
 * now; and the days read again every minute, from the day before the
 * earliest time zone's to the latest one's.
 */

function rollupSourceVisit(array $attributes = []): array
{
    return [
        'id' => 7, 'network_day' => '2026-11-15', 'arrived_at' => '2026-11-15 10:00:00', 'last_seen_at' => '2026-11-15 10:20:00', 'entry_visit_id' => 7, 'channel' => 'direct',
        'source_id' => null, 'campaign_id' => null, 'visitor_hash' => hash('sha256', 'ann'), 'opened_at' => '2026-11-15 10:00:01',
        'known' => false, 'known_reason' => null, 'post_published_at' => null, 'returned_at' => null, 'signup_after_seconds' => null,
        'account_after_seconds' => null, 'value_real' => '0', 'value_expected' => '0', 'started_at' => null, 'completed_at' => null, 'goal_at' => null,
        'signup_form_at' => null, 'signup_at' => null, 'account_at' => null, 'activated_at' => null, 'votes' => 0, 'comments' => 0,
        'posts_created' => 0, 'spend' => '0', 'spend_estimated' => false, 'bot_flags' => 0, 'source_key' => 'direct',
        'campaign_key' => null, 'creative_key' => null, 'landing_key' => 'game', 'device' => 'mobile', 'country' => 'US', 'city' => null,
        'game_id' => null, 'version_id' => null, 'utm_source' => null, 'utm_medium' => null, 'utm_campaign' => null, 'utm_content' => null,
        'utm_term' => null, ...$attributes,
    ];
}

it('knows a browser by the signed 64-bit number a database makes of its hash, and hashes again one not in hex', function () {
    // As PostgreSQL reads them: ('x' || substr(hash, 1, 16))::bit(64)::bigint.
    expect(AnalyticsVisitRow::key(hash('sha256', 'ann')))->toBe(5301121648421453870)
        ->and(AnalyticsVisitRow::key(hash('sha256', 'bob')))->toBe(-9100024591961372966)
        ->and(AnalyticsVisitRow::key('not a hash'))->toBe(AnalyticsVisitRow::key('not a hash'))
        ->and(AnalyticsVisitRow::key('not a hash'))->not->toBe(AnalyticsVisitRow::key('not a hash either'));
});

it('makes each step a flag, keeps the sign-up’s moment and how long after the click, the hash of a visit the tracker counts, and flags the tags, a signed-in arrival and a crawler', function () {
    $row = AnalyticsVisitRow::fromVisit(rollupSourceVisit([
        'started_at' => '2026-11-15 10:01:00', 'signup_at' => '2026-11-15 10:05:00', 'votes' => 3, 'spend' => '0.0125',
        'source_id' => 4, 'bot_flags' => 1 | 4, 'utm_medium' => 'cpc', 'game_id' => '2', 'known' => true, 'known_reason' => 'signed_in',
        'signup_after_seconds' => 300, 'value_expected' => '0.25',
    ]));

    expect($row)->toMatchArray([
        'id' => '7', 'source_id' => '4', 'visitor_key' => '5301121648421453870', 'visitor_hash' => hash('sha256', 'ann'), 'last_seen_at' => '2026-11-15 10:20:00', 'opened' => '1', 'known' => '1', 'signed_in' => '1',
        'started' => '1', 'completed' => '0', 'signed_up' => '1', 'account' => '0', 'signup_at' => '2026-11-15 10:05:00',
        'signup_after_seconds' => '300', 'votes' => '3', 'spend' => '0.01250000', 'value_expected' => '0.25000000', 'crawler' => '1',
        'tagged' => '1', 'game_id' => '2', 'version_id' => null,
    ])->and(array_keys($row))->toBe(AnalyticsVisitRow::COLUMNS);

    $confirmed = AnalyticsVisitRow::fromVisit(rollupSourceVisit(['signup_at' => '2026-11-15 10:05:00', 'account_at' => '2026-11-16 08:00:00', 'opened_at' => null, 'known' => true, 'known_reason' => 'browser']));

    expect([$confirmed['account'], $confirmed['signup_at'], $confirmed['opened'], $confirmed['crawler'], $confirmed['signed_in'], $confirmed['known']])->toBe(['1', '2026-11-15 10:05:00', '0', '0', '0', '1'])
        // A visit the tracker doesn't count — neither a game's nor a paid click — keeps no hash.
        ->and($confirmed['visitor_hash'])->toBeNull();
});

it('reads a stored row the way each engine hands it back as the same row the visit makes', function () {
    $made = AnalyticsVisitRow::fromVisit(rollupSourceVisit(['votes' => 2, 'spend' => '1.5']));
    $stored = [...$made, 'opened' => true, 'known' => 'f', 'votes' => 2, 'id' => 7, 'visitor_key' => '5301121648421453870', 'spend' => '1.50000000', 'arrived_at' => '2026-11-15T10:00:00.000000Z'];
    $sqlite = [...$made, 'opened' => 1, 'known' => 0, 'spend' => 1.5, 'visitor_key' => 5301121648421453870];

    expect(AnalyticsVisitRow::same(AnalyticsVisitRow::fromStored($stored), $made))->toBeTrue()
        ->and(AnalyticsVisitRow::same(AnalyticsVisitRow::fromStored($sqlite), $made))->toBeTrue()
        ->and(AnalyticsVisitRow::same(AnalyticsVisitRow::fromStored([...$stored, 'started' => true]), $made))->toBeFalse();
});

it('reads again every minute from the day before the earliest time zone’s day to the latest one’s', function () {
    expect(AnalyticsRollupWindow::days(CarbonImmutable::parse('2026-11-18 12:00:00', 'UTC')))->toBe(['2026-11-17', '2026-11-18', '2026-11-19'])
        ->and(AnalyticsRollupWindow::days(CarbonImmutable::parse('2026-11-18 03:00:00', 'UTC')))->toBe(['2026-11-16', '2026-11-17', '2026-11-18'])
        ->and(AnalyticsRollupWindow::days(CarbonImmutable::parse('2026-11-18 23:00:00', 'UTC')))->toBe(['2026-11-17', '2026-11-18', '2026-11-19'])
        ->and(AnalyticsRollupWindow::covers('2026-11-16', CarbonImmutable::parse('2026-11-18 12:00:00', 'UTC')))->toBeFalse();
});

it('counts the attempts started on a visit once each, every click on their results’ share buttons, and the attempts with one', function () {
    expect(AnalyticsVisitRow::attemptFigures([
        ['id' => 3, 'share_id' => 10],
        ['id' => 3, 'share_id' => '11'],
        ['id' => '4', 'share_id' => null],
        ['id' => 5, 'share_id' => 12],
    ]))->toBe(['attempts' => 3, 'share_clicks' => 3, 'shared_results' => 2])
        ->and(AnalyticsVisitRow::attemptFigures([]))->toBe(['attempts' => 0, 'share_clicks' => 0, 'shared_results' => 0]);
});

it('finds the post a session opened first, the lower id at the same moment, whatever the engine writes the moment as', function () {
    expect(AnalyticsVisitRow::sessionPost([
        ['post_id' => 9, 'first_at' => '2026-11-15 10:05:00'],
        ['post_id' => '12', 'first_at' => '2026-11-15T10:04:00.000000Z'],
        ['post_id' => 11, 'first_at' => '2026-11-15 10:04:00'],
        ['post_id' => 2, 'first_at' => '2026-11-15 10:06:00'],
    ]))->toBe(11)
        ->and(AnalyticsVisitRow::sessionPost([]))->toBeNull();

    $row = AnalyticsVisitRow::fromVisit(rollupSourceVisit(['attempts' => 2, 'share_clicks' => 1, 'shared_results' => 1, 'session_post_id' => 11]));

    expect([$row['attempts'], $row['share_clicks'], $row['shared_results'], $row['session_post_id']])->toBe(['2', '1', '1', '11'])
        ->and(AnalyticsVisitRow::fromVisit(rollupSourceVisit())['session_post_id'])->toBeNull();
});
