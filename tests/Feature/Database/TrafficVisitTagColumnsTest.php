<?php

use App\Models\TrafficVisit;
use Illuminate\Support\Facades\Schema;

/*
 * Every visit can keep the campaign tags its session came by, alike on every
 * engine: five nullable columns, a campaign's visits read through an index of
 * their own, a visit recorded before them left without tags, and all of it
 * taken off again by down().
 */

function tagColumnsMigration(): object
{
    return require database_path('migrations/2026_11_18_100500_add_utm_tags_to_traffic_visits_table.php');
}

it('keeps the five tags of a visit, reading a campaign’s visits through an index of their own', function () {
    $visit = TrafficVisit::factory()->create([
        'utm_source' => 'reddit',
        'utm_medium' => 'social',
        'utm_campaign' => str_repeat('я', 120),
        'utm_content' => 'banner-a',
        'utm_term' => 'taste test',
    ]);

    expect(Schema::hasColumns('traffic_visits', ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term']))->toBeTrue()
        ->and(collect(Schema::getIndexes('traffic_visits'))->firstWhere('name', 'traffic_visits_utm_campaign_index'))->columns->toBe(['utm_campaign', 'arrived_at'])
        ->and($visit->fresh()->only(['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term']))->toBe([
            'utm_source' => 'reddit',
            'utm_medium' => 'social',
            'utm_campaign' => str_repeat('я', 120),
            'utm_content' => 'banner-a',
            'utm_term' => 'taste test',
        ])
        ->and(TrafficVisit::factory()->create()->fresh()->utm_campaign)->toBeNull();
});

it('undoes what it did and does it again, the visits staying', function () {
    $visit = TrafficVisit::factory()->create(['utm_campaign' => 'spring']);

    // The index of the five tags stands on these columns: off first, back last.
    trafficVisitTagsIndexMigration()->down();
    tagColumnsMigration()->down();

    expect(Schema::hasColumns('traffic_visits', ['utm_source']))->toBeFalse()
        ->and(collect(['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'])->filter(fn (string $column): bool => Schema::hasColumn('traffic_visits', $column))->all())->toBe([])
        ->and(array_column(Schema::getIndexes('traffic_visits'), 'name'))->not->toContain('traffic_visits_utm_campaign_index')
        ->and(TrafficVisit::query()->whereKey($visit->id)->exists())->toBeTrue();

    tagColumnsMigration()->up();
    trafficVisitTagsIndexMigration()->up();

    expect(array_column(Schema::getIndexes('traffic_visits'), 'name'))->toContain('traffic_visits_utm_campaign_index')
        ->and(TrafficVisit::query()->whereKey($visit->id)->value('utm_campaign'))->toBeNull();
});
