<?php

/*
 * The locking code is written for READ COMMITTED, PostgreSQL's default: a
 * plain read after a lock wait sees what the transaction it waited for
 * committed. MariaDB and MySQL default to REPEATABLE READ, so they are set to
 * READ COMMITTED explicitly.
 */
it('reads committed data in a transaction on every engine that would otherwise repeat a snapshot', function (string $connection) {
    expect(config("database.connections.{$connection}.isolation_level"))->toBe('READ COMMITTED');
})->with(['mysql', 'mariadb']);
