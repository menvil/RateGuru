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

/**
 * One competitor, synchronised with the other INSIDE its transaction.
 *
 * The handshake is the whole test. An earlier version waited on a single barrier
 * the parent touched after a fixed sleep, before the transaction had even begun —
 * which synchronised nothing: the first process could read, insert and commit
 * while the second was still booting, and the old race-prone implementation would
 * then pass because there never was a race. A probabilistic test is the one thing
 * a change about "assertions that can actually fail" must not ship.
 *
 * So each competitor opens its transaction, takes the same locked read of the
 * missing row that ProjectSettingsManager::lockedRow is about to take, and only
 * then announces itself and waits for the other. Both are demonstrably past the
 * "row is missing" read before either creates anything, which is the exact
 * interleaving the defect needs. Nobody sleeps for a guessed length of time, and a
 * peer that never arrives is a loud failure rather than a quiet pass.
 *
 * @return array{process: resource, pipes: array<int, resource>, script: string}
 */
function racingFirstWrite(string $barrierDir, string $label): array
{
    $script = <<<'PHP'
        <?php
        [$barrierDir, $base, $label] = [$argv[1], $argv[2], $argv[3]];
        require $base.'/vendor/autoload.php';
        $app = require_once $base.'/bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        $announce = static function () use ($barrierDir, $label): void {
            file_put_contents($barrierDir.'/at-the-read-'.$label, (string) getmypid());
        };

        $awaitPeer = static function () use ($barrierDir): void {
            $deadline = microtime(true) + 20;

            while (microtime(true) < $deadline) {
                if (count(glob($barrierDir.'/at-the-read-*') ?: []) >= 2) {
                    return;
                }

                usleep(1000);
            }

            throw new RuntimeException('the other competitor never reached the missing-row read');
        };

        try {
            Illuminate\Support\Facades\DB::transaction(function () use ($announce, $awaitPeer) {
                // The same locked read lockedRow() performs, taken first so the
                // handshake can happen BETWEEN the read and the create. On
                // PostgreSQL this locks nothing — there is no row — which is the
                // defect: every competitor passes it.
                Illuminate\Support\Facades\DB::table('project_settings')
                    ->where('id', 1)
                    ->lockForUpdate()
                    ->first();

                $announce();
                $awaitPeer();

                // Both competitors are now inside a transaction that has seen no
                // row. Whatever happens next happens concurrently.
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

    $scriptPath = $barrierDir."/competitor-{$label}.php";
    file_put_contents($scriptPath, $script);

    $process = proc_open(
        [PHP_BINARY, $scriptPath, $barrierDir, base_path(), $label],
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
    // A directory the competitors use to find each other. The parent coordinates
    // nothing beyond starting them and reaping them: a parent that decides WHEN the
    // race happens is a parent guessing, and a guess is what made the previous
    // version of this test probabilistic.
    $barrierDir = sys_get_temp_dir().'/rateguru-race-'.bin2hex(random_bytes(6));
    mkdir($barrierDir, 0o700, true);

    $competitors = [racingFirstWrite($barrierDir, 'a'), racingFirstWrite($barrierDir, 'b')];

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
        }
    } finally {
        foreach (glob($barrierDir.'/*') ?: [] as $leftover) {
            @unlink($leftover);
        }

        @rmdir($barrierDir);
    }

    // Both competitors must have announced themselves at the read, or there was no
    // race to observe and the verdict below means nothing.
    foreach ($results as $index => $result) {
        expect($result['output'].$result['stderr'])
            ->not->toContain('never reached the missing-row read', "competitor {$index} raced nobody");
    }

    $report = collect($results)
        ->map(fn (array $r, int $i): string => "competitor {$i}: status={$r['status']} {$r['output']} {$r['stderr']}")
        ->implode("\n");

    // Every assertion is inside the try, because the subprocesses COMMITTED: a row
    // surviving a failed assertion is committed state in this worker's own
    // database that no transaction rolls back, and every later test starting from
    // a fresh installation then fails on its primary key. Cleanup first, verdict
    // second.
    try {
        foreach ($results as $index => $result) {
            expect($result['status'])->toBe(0, "competitor {$index} must not fail the race:\n{$report}");
        }

        // And exactly one row exists: the point is that they agreed, not that both
        // wrote. Read through a connection of its own, for the same reason the
        // cleanup is.
        expect(committedSettingsRowCount())->toBe(1, "exactly one settings row must exist:\n{$report}");
    } finally {
        committedConnection()->table('project_settings')->delete();
    }

    expect(committedSettingsRowCount())->toBe(0, 'the race must leave the worker database as it found it');

})->skip(
    fn (): bool => config('database.default') !== 'pgsql',
    'PostgreSQL only, and not as a convenience: the defect is PostgreSQL-specific — it locks no row that does not exist, so both competitors pass the lock and one insert hits the duplicate key, which is the observable failure this test exists to reproduce. InnoDB takes a gap lock on the same read and kills one transaction with a deadlock before a duplicate can happen, so the old implementation and the new one are indistinguishable there; SQLite runs the suite in memory, where a subprocess cannot join at all. PostgreSQL is the primary runtime (docs/architecture/database-support.md).',
);

it('leaves no settings row behind for the rest of the suite', function () {
    // The race commits for real, so its cleanup has to as well. This is the proof
    // that it did — a row surviving here would leak into every later test that
    // expects a fresh installation.
    expect(committedSettingsRowCount())->toBe(0)
        ->and(ProjectSettings::query()->count())->toBe(0);
})->skip(
    fn (): bool => config('database.default') !== 'pgsql',
    'Paired with the race above, which runs on PostgreSQL only.',
);
