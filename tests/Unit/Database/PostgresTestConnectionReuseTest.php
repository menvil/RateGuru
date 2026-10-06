<?php

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class);

/*
 * RefreshDatabase disconnects after every test, so without a persistent
 * connection each test opens a new PostgreSQL backend (a fork, a SCRAM
 * exchange, a cold catalogue cache) — the cost that made CI's PostgreSQL job
 * the slowest of the three engines. tests/TestCase.php keeps the connection
 * alive across that disconnect; this pins it, because losing it changes no
 * result, only how long every run takes.
 */
it('keeps the same PostgreSQL backend across a disconnect', function () {
    $backend = fn (): int => (int) DB::selectOne('select pg_backend_pid() as pid')->pid;

    $before = $backend();
    DB::disconnect();

    expect($backend())->toBe($before);
})->skip(
    fn (): bool => config('database.default') !== 'pgsql',
    'Connection reuse is configured for PostgreSQL only: SQLite runs in memory and keeps its connection anyway, and a MariaDB connection is cheap to open.',
);
