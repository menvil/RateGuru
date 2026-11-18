<?php

use App\Models\GameAttempt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The attempts played on a visit are found from the visit through an index
 * of their own: the migration adds it, takes it off again with its down(),
 * and leaves the table's own checks in place on every engine.
 */
function trafficVisitIndexMigration(): object
{
    return require database_path('migrations/2026_11_18_100700_add_traffic_visit_index_to_game_attempts_table.php');
}

it('indexes the attempts by the visit they were started on, and takes the index off again', function () {
    $attempt = GameAttempt::factory()->create(['traffic_visit_id' => 41]);

    expect(Schema::hasIndex('game_attempts', 'game_attempts_traffic_visit_index'))->toBeTrue();

    trafficVisitIndexMigration()->down();
    expect(Schema::hasIndex('game_attempts', 'game_attempts_traffic_visit_index'))->toBeFalse();

    trafficVisitIndexMigration()->up();
    expect(Schema::hasIndex('game_attempts', 'game_attempts_traffic_visit_index'))->toBeTrue()
        ->and(GameAttempt::query()->where('traffic_visit_id', 41)->value('id'))->toBe($attempt->id);
});

it('keeps the checks SQLite would lose if it rebuilt the table', function () {
    expect((string) DB::table('sqlite_master')->where('name', 'game_attempts')->value('sql'))
        ->toContain('"status" in')
        ->toContain('"audience" in');
})->skip(fn (): bool => DB::getDriverName() !== 'sqlite', 'SQLite is the engine that rebuilds a table and loses its checks.');
