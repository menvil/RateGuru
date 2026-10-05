<?php

use App\Models\ProjectSettings;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/*
 * Two first saves of the project settings, racing for real.
 *
 * PostgreSQL locks no row that does not exist, so `lockForUpdate()->find(1)` on a
 * fresh installation returns null for every competitor at once, and each then
 * inserts id 1. The previous implementation let one of them fail on the duplicate
 * key; ProjectSettingsManager::lockedRow settles it with firstOrCreate, which
 * re-reads on a unique violation.
 *
 * It has to be two PROCESSES. RefreshDatabase holds one transaction open on the
 * test's connection for the whole test, so a second connection opened in-process
 * either cannot see the test's state or waits on its locks — and a sequential
 * pair of calls proves only idempotence, which the old implementation also had.
 * Each process gets its own connection and commits for real, which is the
 * situation being tested.
 *
 * The rows they commit outlive the test's own transaction, so the test removes
 * them itself.
 */

/** @return array{status: int, output: string} */
function racingFirstWrite(string $barrier, string $label): array
{
    $script = <<<'PHP'
        <?php
        [$barrier, $base] = [$argv[1], $argv[2]];
        require $base.'/vendor/autoload.php';
        $app = require_once $base.'/bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        // Both competitors wait here, so both are past the "row is missing" read
        // before either inserts. Without it the second would simply find the row.
        $deadline = microtime(true) + 10;
        while (! file_exists($barrier) && microtime(true) < $deadline) {
            usleep(2000);
        }

        try {
            Illuminate\Support\Facades\DB::transaction(function () {
                $row = app(App\Support\Settings\ProjectSettingsManager::class)->lockedRow();
                $row->fill(['site_name' => 'Raced'])->save();
            });
            fwrite(STDOUT, "ok\n");
            exit(0);
        } catch (Throwable $e) {
            fwrite(STDOUT, 'failed: '.get_class($e).': '.$e->getMessage()."\n");
            exit(1);
        }
        PHP;

    $scriptPath = sys_get_temp_dir()."/rateguru-race-{$label}.php";
    file_put_contents($scriptPath, $script);

    $process = proc_open(
        [PHP_BINARY, $scriptPath, $barrier, base_path()],
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        base_path(),
        racingEnvironment(),
    );

    expect($process)->not->toBeFalse("the {$label} competitor must start");

    return ['process' => $process, 'pipes' => $pipes, 'script' => $scriptPath];
}

/**
 * The database the test itself is using, handed to the subprocesses so they
 * compete in the same place rather than against whatever `.env` happens to say.
 *
 * @return array<string, string>
 */
function racingEnvironment(): array
{
    $name = (string) config('database.default');

    // The database name comes from the LIVE connection, not from config: under
    // --parallel each worker runs against its own database (rateguru_test_test_N)
    // and the connection knows which one, while the configured value is still the
    // base name. A subprocess sent to the base database finds no tables at all.
    //
    // APP_ENV is pinned so the children behave like a deployment rather than
    // inheriting the worker's testing environment.
    // The explicit values come FIRST: PHP's `+` keeps the left operand, so putting
    // the inherited environment first would let the worker's own DB_DATABASE — the
    // base name, not this worker's — override everything resolved here.
    return array_filter([
        'APP_ENV' => 'production',
        'DB_CONNECTION' => $name,
        'DB_HOST' => (string) config("database.connections.{$name}.host"),
        'DB_PORT' => (string) config("database.connections.{$name}.port"),
        'DB_DATABASE' => (string) DB::connection()->getDatabaseName(),
        'DB_USERNAME' => (string) config("database.connections.{$name}.username"),
        'DB_PASSWORD' => (string) config("database.connections.{$name}.password"),
    ], fn (string $value): bool => $value !== '') + array_filter($_SERVER, 'is_string');
}

/**
 * A connection outside the test's own transaction, for state that was committed
 * by another process and therefore has to be read and removed for real.
 */
function committedConnection(): ConnectionInterface
{
    $default = (string) config('database.default');

    config(['database.connections.committed_peer' => config("database.connections.{$default}")]);
    DB::purge('committed_peer');

    return DB::connection('committed_peer');
}

function committedSettingsRowCount(): int
{
    return committedConnection()->table('project_settings')->count();
}

it('settles two concurrent first writes instead of failing one of them', function () {
    $barrier = sys_get_temp_dir().'/rateguru-race-'.bin2hex(random_bytes(6));

    $competitors = [racingFirstWrite($barrier, 'a'), racingFirstWrite($barrier, 'b')];

    // Both are now booted and spinning on the barrier.
    usleep(400_000);
    touch($barrier);

    $results = [];

    try {
        foreach ($competitors as $competitor) {
            $results[] = [
                'output' => trim((string) stream_get_contents($competitor['pipes'][1])),
                'stderr' => trim((string) stream_get_contents($competitor['pipes'][2])),
            ];
            fclose($competitor['pipes'][1]);
            fclose($competitor['pipes'][2]);
            $results[count($results) - 1]['status'] = proc_close($competitor['process']);
            @unlink($competitor['script']);
        }
    } finally {
        @unlink($barrier);
    }

    $report = collect($results)
        ->map(fn (array $r, int $i): string => "competitor {$i}: status={$r['status']} {$r['output']} {$r['stderr']}")
        ->implode("\n");

    foreach ($results as $index => $result) {
        expect($result['status'])->toBe(0, "competitor {$index} must not fail the race:\n{$report}");
    }

    // And exactly one row exists: the point is that they agreed, not that both
    // wrote.
    //
    // Both the read and the cleanup go through a connection of their own. The
    // subprocesses COMMITTED, outside the transaction RefreshDatabase holds here,
    // so a delete issued on the test's connection would be rolled back with
    // everything else and the row would outlive the test.
    try {
        expect(committedSettingsRowCount())->toBe(1, "exactly one settings row must exist:\n{$report}");
    } finally {
        committedConnection()->table('project_settings')->delete();
    }
})->skip(
    fn (): bool => config('database.default') === 'sqlite',
    'SQLite runs the suite against an in-memory database that a subprocess cannot join, and concurrent write semantics there are not a deployed-runtime concern (docs/architecture/database-support.md).',
);

it('leaves no settings row behind for the rest of the suite', function () {
    // The race commits for real, so its cleanup has to as well. This is the proof
    // that it did — a row surviving here would leak into every later test that
    // expects a fresh installation.
    expect(committedSettingsRowCount())->toBe(0)
        ->and(ProjectSettings::query()->count())->toBe(0);
})->skip(
    fn (): bool => config('database.default') === 'sqlite',
    'Paired with the race above, which does not run on SQLite.',
);
