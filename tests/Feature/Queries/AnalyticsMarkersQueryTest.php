<?php

use App\Models\Game;
use App\Models\GameVersion;
use App\Models\TrafficCampaign;
use App\Models\TrafficSource;
use App\Queries\Analytics\AnalyticsMarkersQuery;
use Carbon\CarbonImmutable;

/*
 * What an Analytics chart marks: a version published, a paid campaign's
 * first click and a comparison period started, within the period, the newest
 * first and no more than asked; a paid campaign only where the source and
 * campaign chosen leave it in; a period of one version, or hidden from
 * Experiments, never.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-18 12:00:00'));
});

/** The markers' texts, the newest first. */
function analyticsMarkers(array $filter = [], int $most = 6): array
{
    return array_column(app(AnalyticsMarkersQuery::class)->in(analyticsFilter(...$filter), $most), 'text');
}

it('marks the versions published, the paid campaigns started and the comparisons begun in the period, the newest first', function () {
    $game = Game::factory()->create(['title' => 'Trust Your Taste']);
    GameVersion::factory()->create(['game_id' => $game->id, 'number' => 7, 'published_at' => '2026-11-13 09:00:00']);
    // Before the period, and a period of one version, and one hidden from Experiments: none of them.
    GameVersion::factory()->create(['game_id' => $game->id, 'number' => 6, 'published_at' => '2026-11-01 09:00:00']);
    GameVersion::factory()->create(['game_id' => $game->id, 'number' => 8, 'published_at' => '2026-10-01 09:00:00']);
    comparisonPeriod($game, [7 => 50, 8 => 50], '2026-11-16 10:00:00');
    comparisonPeriod($game, [7 => 100], '2026-11-15 10:00:00');
    comparisonPeriod($game, [7 => 80, 8 => 20], '2026-11-14 10:00:00')->forceFill(['hidden_at' => now()])->save();
    TrafficCampaign::factory()->create(['name' => 'US popunder', 'first_click_at' => '2026-11-17 08:00:00']);
    TrafficCampaign::factory()->create(['name' => null, 'external_id' => '990081', 'first_click_at' => '2026-11-12 08:00:00']);

    expect(analyticsMarkers())->toBe([
        'US popunder started',
        'Trust Your Taste: V7 · V8 comparison started for anonymous visitors',
        'V7 of Trust Your Taste published',
        'Campaign 990081 started',
    ])->and(analyticsMarkers(most: 2))->toBe(['US popunder started', 'Trust Your Taste: V7 · V8 comparison started for anonymous visitors'])
        ->and(analyticsMarkers(most: 0))->toBe([]);
});

it('marks a paid campaign only where the source and campaign chosen leave it in', function () {
    $exo = TrafficSource::factory()->create();
    $popunder = TrafficCampaign::factory()->create(['source_id' => $exo->id, 'name' => 'US popunder', 'first_click_at' => '2026-11-17 08:00:00']);
    TrafficCampaign::factory()->create(['name' => 'Banner', 'first_click_at' => '2026-11-16 08:00:00']);

    expect(analyticsMarkers(['source' => 'paid:'.$exo->id]))->toBe(['US popunder started'])
        ->and(analyticsMarkers(['campaign' => 'paid:'.$popunder->id]))->toBe(['US popunder started'])
        ->and(analyticsMarkers(['source' => 'reddit:organic']))->toBe([])
        ->and(analyticsMarkers(['campaign' => 'tag:spring']))->toBe([]);
});
