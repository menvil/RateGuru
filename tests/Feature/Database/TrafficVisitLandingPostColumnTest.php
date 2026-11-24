<?php

use App\Models\TrafficVisit;
use Illuminate\Support\Facades\Schema;

/**
 * The post a session landed on, kept on its visits for the registration
 * funnel's landing filter; empty on a session that landed elsewhere.
 */
it('keeps the post a session landed on, none for one that landed elsewhere', function () {
    expect(Schema::hasColumn('traffic_visits', 'landing_post_id'))->toBeTrue()
        ->and(TrafficVisit::factory()->create()->fresh()->landing_post_id)->toBeNull();
});

it('undoes what it added and adds it again, the visits staying', function () {
    $visit = TrafficVisit::factory()->create();
    $migration = require database_path('migrations/2026_11_24_130000_add_landing_post_to_traffic_visits_table.php');

    $migration->down();

    expect(Schema::hasColumn('traffic_visits', 'landing_post_id'))->toBeFalse();

    $migration->up();

    expect(Schema::hasColumn('traffic_visits', 'landing_post_id'))->toBeTrue()
        ->and(TrafficVisit::query()->whereKey($visit->id)->exists())->toBeTrue();
});
