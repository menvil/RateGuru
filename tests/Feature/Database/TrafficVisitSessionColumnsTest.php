<?php

use App\Enums\TrafficChannel;
use App\Models\TrafficCampaign;
use App\Models\TrafficVisit;
use Illuminate\Support\Facades\Schema;

/*
 * Every visit carries the visit its session began with and where that one
 * came from, alike on every engine: the columns are nullable, read through
 * their own indexes, filled in for the visits recorded before them — each
 * its own session, keyed by what it kept — and taken off again by down().
 */

function sessionColumnsMigration(): object
{
    return require database_path('migrations/2026_11_18_100300_add_session_entry_columns_to_traffic_visits_table.php');
}

it('reads visits by their session, source and campaign through indexes of their own', function () {
    $indexes = collect(Schema::getIndexes('traffic_visits'))->keyBy('name');

    expect(Schema::hasColumns('traffic_visits', ['entry_visit_id', 'source_key', 'campaign_key', 'creative_key', 'landing_key']))->toBeTrue()
        ->and($indexes->get('traffic_visits_entry_index'))->columns->toBe(['entry_visit_id'])
        ->and($indexes->get('traffic_visits_source_key_index'))->columns->toBe(['source_key', 'arrived_at'])
        ->and($indexes->get('traffic_visits_campaign_key_index'))->columns->toBe(['campaign_key', 'arrived_at'])
        ->and(TrafficVisit::factory()->create()->fresh()->entry_visit_id)->toBeNull();
});

it('makes each visit recorded before sessions its own, keyed by what it kept', function () {
    sessionColumnsMigration()->down();

    $draft = publishableGameDraft();
    $version = liveGame($draft);
    $game = $draft['game'];
    $campaign = TrafficCampaign::factory()->create();
    $paid = TrafficVisit::factory()->create(['source_id' => $campaign->source_id, 'campaign_id' => $campaign->id, 'creative_ref' => 'video-03', 'game_id' => $game->id]);
    $search = TrafficVisit::factory()->unpaid(TrafficChannel::Search)->create(['origin' => 'google', 'game_id' => $game->id, 'version_id' => $version->id]);
    $referral = TrafficVisit::factory()->unpaid(TrafficChannel::Referral)->create(['origin' => 'reddit.com']);
    $unknown = TrafficVisit::factory()->unpaid(TrafficChannel::Referral)->create(['origin' => 'news.ycombinator.com']);
    $shared = TrafficVisit::factory()->unpaid(TrafficChannel::Shared)->create(['origin' => 'result page']);
    $direct = TrafficVisit::factory()->unpaid()->create();
    $internal = TrafficVisit::factory()->unpaid(TrafficChannel::Internal)->create(['origin' => 'feed']);

    sessionColumnsMigration()->up();

    $keys = fn (TrafficVisit $visit): array => TrafficVisit::query()->whereKey($visit->id)->first(['id', 'entry_visit_id', 'source_key', 'campaign_key', 'creative_key', 'landing_key'])->toArray();

    expect($keys($paid))->toBe(['id' => $paid->id, 'entry_visit_id' => $paid->id, 'source_key' => 'paid:'.$campaign->source_id, 'campaign_key' => 'paid:'.$campaign->id, 'creative_key' => 'video-03', 'landing_key' => 'games.play:'.$game->id])
        ->and($keys($search))->toBe(['id' => $search->id, 'entry_visit_id' => $search->id, 'source_key' => 'google:organic', 'campaign_key' => null, 'creative_key' => null, 'landing_key' => 'games.play:'.$game->id.':'.$version->id])
        ->and($keys($referral)['source_key'])->toBe('reddit:organic')
        ->and($keys($unknown)['source_key'])->toBe('host.ycombinator.com:organic')
        ->and($keys($shared)['source_key'])->toBe('shared')
        ->and($keys($direct)['source_key'])->toBe('direct')
        ->and($keys($internal))->toBe(['id' => $internal->id, 'entry_visit_id' => $internal->id, 'source_key' => 'internal', 'campaign_key' => null, 'creative_key' => null, 'landing_key' => 'other']);
});

it('undoes what it did and does it again', function () {
    $visit = TrafficVisit::factory()->create();

    sessionColumnsMigration()->down();

    $indexes = ['traffic_visits_entry_index', 'traffic_visits_source_key_index', 'traffic_visits_campaign_key_index'];

    foreach (['entry_visit_id', 'source_key', 'campaign_key', 'creative_key', 'landing_key'] as $column) {
        expect(Schema::hasColumn('traffic_visits', $column))->toBeFalse();
    }

    expect(array_values(array_intersect($indexes, array_column(Schema::getIndexes('traffic_visits'), 'name'))))->toBe([])
        ->and(TrafficVisit::query()->whereKey($visit->id)->exists())->toBeTrue();

    sessionColumnsMigration()->up();

    expect(array_column(Schema::getIndexes('traffic_visits'), 'name'))->toContain(...$indexes)
        ->and(TrafficVisit::query()->whereKey($visit->id)->value('entry_visit_id'))->toBe($visit->id);
});
