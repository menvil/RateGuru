<?php

use App\Models\TrackingLink;
use App\Models\TrafficVisit;
use App\Queries\Analytics\TrackingLinksQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a visit came by a set of five campaign tags is answered through an
 * index of the five: the migration adds it, takes it off again with its
 * down(), and whether each tracking link was visited reads the same either
 * way.
 */
it('indexes the visits by their five tags, and takes the index off again, whether a link was visited read the same', function () {
    test()->travelTo(CarbonImmutable::parse('2026-11-18 12:00:00'));
    $tags = ['utm_source' => 'reddit', 'utm_medium' => 'organic_social', 'utm_campaign' => 'launch'];
    $post = TrackingLink::factory()->create([...$tags, 'utm_content' => 'post_01', 'utm_term' => '']);
    $bare = TrackingLink::factory()->create([...$tags, 'utm_content' => '', 'utm_term' => '']);
    $idle = TrackingLink::factory()->create([...$tags, 'utm_content' => 'post_02', 'utm_term' => '']);
    analyticsVisit('ann', [...$tags, 'utm_content' => 'post_01']);
    analyticsVisit('bob', $tags);

    $links = app(TrackingLinksQuery::class);
    $visited = fn (): array => $links->list('', analyticsFilter('7d'))['links']
        ->mapWithKeys(fn (TrackingLink $link): array => [$link->id => $link->getAttribute('visit_id') !== null])
        ->sortKeys()
        ->all();
    $expected = [$post->id => true, $bare->id => true, $idle->id => false];
    ksort($expected);

    $index = collect(Schema::getIndexes('traffic_visits'))->firstWhere('name', 'traffic_visits_tags_index');

    expect($index['columns'] ?? null)->toBe(['utm_campaign', 'utm_source', 'utm_medium', 'utm_content', 'utm_term'])
        ->and($index['unique'] ?? null)->toBeFalse()
        ->and($visited())->toBe($expected)
        ->and($links->hasVisits($idle))->toBeFalse();

    trafficVisitTagsIndexMigration()->down();
    expect(Schema::hasIndex('traffic_visits', 'traffic_visits_tags_index'))->toBeFalse()
        ->and($visited())->toBe($expected);

    trafficVisitTagsIndexMigration()->up();
    expect(Schema::hasIndex('traffic_visits', 'traffic_visits_tags_index'))->toBeTrue()
        ->and(TrafficVisit::query()->count())->toBe(2);
});
