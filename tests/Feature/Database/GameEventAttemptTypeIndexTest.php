<?php

use App\Enums\GameAudience;
use App\Enums\GameEventType;
use App\Models\GameAttempt;
use App\Models\GameEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An attempt's events are found by type from an index that leads with the
 * attempt and holds the type and step beside it. It takes the place of the
 * attempt's own index, so the foreign key on the attempt stays led by an
 * index on every engine, on the way up and on the way down, and the table
 * keeps its checks.
 */
function attemptTypeIndexMigration(): object
{
    return require database_path('migrations/2026_11_21_100400_add_attempt_type_index_to_game_events_table.php');
}

/** @return list<list<string>> the columns of each index of the events */
function gameEventIndexColumns(): array
{
    return array_values(array_map(fn (array $index): array => $index['columns'], Schema::getIndexes('game_events')));
}

it('finds an attempt’s events by type from one index in place of the attempt’s own, and puts that one back on the way down', function () {
    $attempt = GameAttempt::factory()->create();
    (new GameEvent)->forceFill([
        'game_id' => $attempt->game_id, 'version_id' => $attempt->version_id, 'attempt_id' => $attempt->id, 'audience' => GameAudience::Anonymous,
        'visitor_hash' => $attempt->visitor_hash, 'type' => GameEventType::ResultViewed, 'step_type' => 'full_result', 'occurred_at' => now(),
    ])->save();
    $ledByAttempt = fn (): bool => collect(gameEventIndexColumns())->contains(fn (array $columns): bool => $columns[0] === 'attempt_id');

    expect(Schema::hasIndex('game_events', 'game_events_attempt_type_index'))->toBeTrue()
        ->and(gameEventIndexColumns())->toContain(['attempt_id', 'type', 'step_type'])
        ->and(Schema::hasIndex('game_events', 'game_events_attempt_index'))->toBeFalse();

    attemptTypeIndexMigration()->down();
    expect(Schema::hasIndex('game_events', 'game_events_attempt_type_index'))->toBeFalse()
        ->and(Schema::hasIndex('game_events', 'game_events_attempt_index'))->toBeTrue()
        ->and($ledByAttempt())->toBeTrue();

    attemptTypeIndexMigration()->up();
    expect(Schema::hasIndex('game_events', 'game_events_attempt_type_index'))->toBeTrue()
        ->and(Schema::hasIndex('game_events', 'game_events_attempt_index'))->toBeFalse()
        ->and($ledByAttempt())->toBeTrue()
        ->and(GameEvent::query()->where('attempt_id', $attempt->id)->where('type', GameEventType::ResultViewed->value)->count())->toBe(1);
});

it('keeps the checks SQLite would lose if it rebuilt the table', function () {
    expect((string) DB::table('sqlite_master')->where('name', 'game_events')->value('sql'))->toContain('"audience" in');
})->skip(fn (): bool => DB::getDriverName() !== 'sqlite', 'SQLite is the engine that rebuilds a table and loses its checks.');
