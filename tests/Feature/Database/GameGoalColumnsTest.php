<?php

use App\Models\GameVersion;
use Illuminate\Support\Facades\DB;

/**
 * Goals came after the first versions were published: the migration that
 * adds them gives every earlier version the goal its flow's steps gave it, by
 * the rule as it stood, and leaves the game tables' own checks in place on
 * every engine.
 */
function versionWithSteps(array $steps): GameVersion
{
    $version = GameVersion::factory()->create();
    $snapshot = $version->snapshot;
    $snapshot['flow']['steps'] = array_map(fn (array $step): array => ['key' => 's'.random_int(1, 9999), 'audience' => 'all', 'texts' => [], ...$step], $steps);
    DB::table('game_versions')->where('id', $version->id)->update(['snapshot' => json_encode($snapshot), 'goal' => null]);

    return $version;
}

it('gives every earlier version the goal its flow\'s steps gave it', function (array $steps, string $goal) {
    $version = versionWithSteps($steps);
    $migration = require database_path('migrations/2026_11_17_100000_add_goal_to_game_flows_and_versions.php');

    $migration->down();
    $migration->up();

    expect(DB::table('game_versions')->where('id', $version->id)->value('goal'))->toBe($goal);
})->with([
    'a separate sign-up step' => [[['type' => 'game', 'settings' => []], ['type' => 'full_result', 'settings' => ['signup' => 'step']], ['type' => 'registration', 'settings' => []]], 'account'],
    'sign-up on the result' => [[['type' => 'landing', 'settings' => []], ['type' => 'game', 'settings' => []], ['type' => 'full_result', 'settings' => ['signup' => 'inline']]], 'account'],
    'an email step' => [[['type' => 'game', 'settings' => []], ['type' => 'email_capture', 'settings' => []], ['type' => 'full_result', 'settings' => ['signup' => 'none']]], 'email'],
    'a share step and the feed' => [[['type' => 'game', 'settings' => []], ['type' => 'full_result', 'settings' => ['signup' => 'none']], ['type' => 'share', 'settings' => []], ['type' => 'feed', 'settings' => []]], 'shared'],
    'nothing after the result' => [[['type' => 'game', 'settings' => []], ['type' => 'full_result', 'settings' => ['signup' => 'none']]], 'completed'],
]);

it('keeps the checks of the game tables it added columns to', function () {
    $sql = fn (string $table): string => (string) DB::table('sqlite_master')->where('name', $table)->value('sql');

    expect($sql('game_attempts'))->toContain('"status" in')->toContain('"audience" in')
        ->and($sql('game_versions'))->toContain('"status" in')
        ->and(DB::getSchemaBuilder()->hasColumns('game_attempts', ['goal_reached_at', 'traffic_visit_id', 'onward_kind', 'onward_post_id', 'onward_at']))->toBeTrue()
        ->and(DB::getSchemaBuilder()->hasColumn('game_versions', 'goal'))->toBeTrue();
})->skip(fn (): bool => DB::getDriverName() !== 'sqlite', 'SQLite is the engine that rebuilds a table and loses its checks.');
