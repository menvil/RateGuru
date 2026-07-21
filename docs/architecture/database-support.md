# Database support

## Supported runtime

PostgreSQL is the primary runtime database for RateGuru. Local development,
the default PHPUnit configuration, CI, coverage, staging, and the recommended
production configuration use PostgreSQL 17.

SQLite and MariaDB are supported compatibility targets. The application keeps
portable migrations and query behavior, and the Unit and Feature suites run on
all three engines in CI. Browser tests run once against PostgreSQL because they
exercise the same application behavior and are substantially more expensive.

Operational support is intentionally different from application compatibility:
each deployment still needs engine-appropriate backup, restore, monitoring, and
upgrade procedures.

## Development and tests

Start the primary local database and run its tests:

```bash
composer db:start
composer test
```

The explicit alias `composer test:postgres` does the same thing. PostgreSQL uses
`rateguru` for development and `rateguru_test` for tests, so test resets cannot
erase the development database.

Use SQLite when a PostgreSQL service is unavailable or a very fast local loop is
useful:

```bash
composer test:sqlite
```

Use `composer test:mariadb` when a compatible local MariaDB test database is
available. The command expects the documented non-production development
credentials; CI provides its own isolated service with those values.

## CI database matrix

The primary Pest and Browser job runs on PostgreSQL. Coverage also runs on
PostgreSQL. Separate compatibility jobs run the complete Unit and Feature suites,
fresh migrations, the standard seed, and rollback checks on SQLite and MariaDB.

This matrix certifies application behavior covered by the automated suite. It
does not claim identical query plans, locking throughput, collation rules outside
the tested behavior, or interchangeable database backup formats.

## Portable database code

Raw expressions are isolated in the approved Query Objects documented in
[HTTP and database boundaries](http-and-database-boundaries.md). New migrations,
constraints, indexes, and queries must pass the three-engine CI matrix. Any
engine-specific implementation requires a documented technical exception and a
shared behavior test.

## Switching an existing checkout

Changing `DB_CONNECTION` does not move data. Keep the previous database intact,
configure the target connection, run `php artisan migrate:fresh --seed` only for
disposable development environments, and verify the application before removing
old data. Production data must use a rehearsed export/import and rollback plan.
