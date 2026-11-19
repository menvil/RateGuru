<?php

use App\Models\Game;
use App\Models\GameTrafficPeriod;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * A traffic period can be hidden from Growth → Experiments: the column the
 * migration adds is nullable, comes off again with its down(), and leaves the
 * table's own check on its audience in place on every engine.
 */
function hiddenColumnMigration(): object
{
    return require database_path('migrations/2026_11_18_100200_add_hidden_at_to_game_traffic_periods_table.php');
}

it('adds a nullable hidden_at, and takes it off again', function () {
    $period = GameTrafficPeriod::factory()->create();

    expect($period->fresh()->hidden_at)->toBeNull();

    hiddenColumnMigration()->down();
    expect(DB::getSchemaBuilder()->hasColumn('game_traffic_periods', 'hidden_at'))->toBeFalse();

    hiddenColumnMigration()->up();
    expect(DB::getSchemaBuilder()->hasColumn('game_traffic_periods', 'hidden_at'))->toBeTrue()
        ->and(GameTrafficPeriod::query()->whereKey($period->id)->exists())->toBeTrue();
});

it('still refuses an audience the table doesn’t know', function () {
    $insert = fn (string $audience) => DB::table('game_traffic_periods')->insert([
        'game_id' => Game::factory()->create()->id,
        'audience' => $audience,
        'started_at' => now(),
        'started_by_user_id' => User::factory()->admin()->create()->id,
        'hidden_at' => now(),
    ]);

    $insert('anonymous');

    expect(fn () => $insert('everyone'))->toThrow(QueryException::class);
});

it('keeps the check SQLite would lose if it rebuilt the table', function () {
    expect((string) DB::table('sqlite_master')->where('name', 'game_traffic_periods')->value('sql'))
        ->toContain('"audience" in')
        ->toContain('"hidden_at" datetime');
})->skip(fn (): bool => DB::getDriverName() !== 'sqlite', 'SQLite is the engine that rebuilds a table and loses its checks.');
