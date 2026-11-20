<?php

use App\Models\TrafficVisit;
use Illuminate\Support\Facades\Schema;

/**
 * When a member first voted, commented or made a post on a visit: the
 * migration adds an empty column for it, takes it off again with its down(),
 * and the visits stay as they were either way.
 */
function actedAtMigration(): object
{
    return require database_path('migrations/2026_11_20_100000_add_acted_at_to_traffic_visits_table.php');
}

it('adds an empty moment of the first vote, comment or post to the visits, and takes it off again', function () {
    analyticsVisit('ann', ['network_day' => '2026-11-15', 'arrived_at' => '2026-11-15 10:00:00', 'votes' => 2]);

    expect(Schema::hasColumn('traffic_visits', 'acted_at'))->toBeTrue()
        ->and(TrafficVisit::query()->sole()->acted_at)->toBeNull();

    actedAtMigration()->down();
    expect(Schema::hasColumn('traffic_visits', 'acted_at'))->toBeFalse();

    actedAtMigration()->up();
    expect(Schema::hasColumn('traffic_visits', 'acted_at'))->toBeTrue()
        ->and(TrafficVisit::query()->sole())->votes->toBe(2)->acted_at->toBeNull();
});
