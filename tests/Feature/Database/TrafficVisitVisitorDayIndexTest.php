<?php

use App\Models\TrafficVisit;
use App\Queries\Analytics\AnalyticsVisitsQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a browser came before a day is answered through an index of the
 * browser and the day: the migration adds it, takes it off again with its
 * down(), and who came back reads the same either way.
 */
function visitorDayIndexMigration(): object
{
    return require database_path('migrations/2026_11_18_101400_add_visitor_day_index_to_traffic_visits_table.php');
}

it('indexes the visits by browser and day, and takes the index off again, who came back read the same', function () {
    test()->travelTo(CarbonImmutable::parse('2026-11-18 12:00:00'));
    analyticsVisit('ann', ['network_day' => '2026-11-15', 'arrived_at' => '2026-11-15 10:00:00']);
    analyticsVisit('ann', ['network_day' => '2026-11-01', 'arrived_at' => '2026-11-01 10:00:00']);
    analyticsVisit('bob', ['network_day' => '2026-11-16', 'arrived_at' => '2026-11-16 10:00:00']);
    // A page that never ran is no earlier visit.
    analyticsVisit('cid', ['network_day' => '2026-11-16', 'arrived_at' => '2026-11-16 10:00:00']);
    analyticsVisit('cid', ['network_day' => '2026-11-02', 'arrived_at' => '2026-11-02 10:00:00', 'opened_at' => null]);
    $returning = fn (): int => app(AnalyticsVisitsQuery::class)->returning(analyticsFilter('7d'));

    $index = collect(Schema::getIndexes('traffic_visits'))->firstWhere('name', 'traffic_visits_visitor_day_index');

    expect($index['columns'] ?? null)->toBe(['visitor_hash', 'network_day'])
        ->and($index['unique'] ?? null)->toBeFalse()
        ->and($returning())->toBe(1);

    visitorDayIndexMigration()->down();
    expect(Schema::hasIndex('traffic_visits', 'traffic_visits_visitor_day_index'))->toBeFalse()
        ->and($returning())->toBe(1);

    visitorDayIndexMigration()->up();
    expect(Schema::hasIndex('traffic_visits', 'traffic_visits_visitor_day_index'))->toBeTrue()
        ->and(TrafficVisit::query()->count())->toBe(5);
});
