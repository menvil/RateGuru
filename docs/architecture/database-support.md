# Database support

## Supported runtime

PostgreSQL 18.4 is the minimum supported primary runtime for RateGuru. Local
development, the default PHPUnit configuration, CI, coverage, staging, and the
recommended production configuration use this baseline or a newer compatible
minor release.

SQLite and MariaDB are supported compatibility targets. The application keeps
portable migrations and query behavior, and the Unit and Feature suites run on
all three engines in CI. Browser tests run once against PostgreSQL because they
exercise the same application behavior and are substantially more expensive.

The **Architecture** suite is deliberately not one of the three. It is a
separate PHPUnit test suite (`composer test:architecture`, and its own CI job)
because it reads committed files and drives real shell subprocesses against a
simulated host: no Architecture test issues a query, so running it against
MariaDB and SQLite could not discover anything an engine might disagree about.
It ran there only because it happened to live under `tests/Feature`, which cost
both compatibility jobs the whole suite and made a shell-script regression
report itself as a database failure.

The **PHPStan** suite, the tests of this repository's own PHPStan rules against
their fixtures, is not one of the three either. It needs neither the
application nor a database, so it runs once, in the static analysis job that
applies those rules (`composer test:phpstan`).

Every suite runs in parallel (`--parallel`). Parallel testing gives each worker
its own database, `<database>_test_<token>`, created on first use — which is
why the MariaDB CI job widens the test user's grant to that family of names.

PostgreSQL, MariaDB and MySQL run transactions at READ COMMITTED, PostgreSQL's
default and the level the locking code is written for: a plain read after a
lock wait sees what the transaction it waited for committed. MariaDB and MySQL
default to REPEATABLE READ, so their connections set READ COMMITTED explicitly
(`config/database.php`). SQLite has no such levels: it lets one writer at a
time, so a transaction never reads from before a write it waited for.

Operational support is intentionally different from application compatibility:
each deployment still needs engine-appropriate backup, restore, monitoring, and
upgrade procedures.

## PostgreSQL extensions

PostgreSQL deployments carry one extension, **roaringbitmap**
(`pg_roaringbitmap`, from PGDG): Growth → Analytics keeps each day's people as a
roaring bitmap, so a period's unique people are a union of days rather than a
sort of every visit — and each game's people of a day the same way, so a
game's visitors and players are a union of days rather than a grouping of
every view and attempt. It is an optional fast path, never a requirement — the
application uses it where it finds it and otherwise reads exactly as SQLite and
MariaDB do, so neither compatibility target is affected and no migration
creates or needs it.

- **Hosts.** `install-bootstrap-runtime` installs `postgresql-18-roaringbitmap`;
  `install-target-database` creates the extension in each target's database as
  the `postgres` superuser, because an extension not marked trusted needs one
  and the application role never is one. Restores create it the same way
  before restoring the dump as the application role (see
  `infrastructure/runbooks/restore-target.md`).
- **CI.** Every PostgreSQL job installs the package into its service container
  and creates the extension in the test database and in `template1`, which a
  parallel run copies for each worker.
- **Locally.** Homebrew has no package for it; build it once against the
  Homebrew PostgreSQL, then create it as your own (superuser) role in the test
  database and in `template1`, which a parallel run copies for each worker:

  ```bash
  git clone --depth 1 --branch v1.3.0 https://github.com/ChenHuajun/pg_roaringbitmap.git
  make -C pg_roaringbitmap install PG_CONFIG="$(brew --prefix postgresql@18)/bin/pg_config"
  psql -d rateguru_test -c 'CREATE EXTENSION IF NOT EXISTS roaringbitmap'
  psql -d template1 -c 'CREATE EXTENSION IF NOT EXISTS roaringbitmap'
  ```

  Worker databases created before that (`rateguru_test_test_<token>`) keep
  lacking it: run the suite once with `--recreate-databases`, or create the
  extension in each. `brew upgrade postgresql@18` removes the built files;
  build it again after one.

## Development and tests

Install and start the primary local database, then run its tests:

```bash
brew install postgresql@18
brew services start postgresql@18
composer test
```

The one-time role and database creation commands are documented in the project
README. Docker is not required for local development. The explicit alias
`composer test:postgres` does the same thing as `composer test`. PostgreSQL uses
`rateguru` for development and `rateguru_test` for tests, so test resets cannot
erase the development database.

Use SQLite when a PostgreSQL service is unavailable or a very fast local loop is
useful:

```bash
composer test:sqlite
```

Run the Architecture suite on its own with `composer test:architecture`; it
needs a reachable database only because `tests/Pest.php` binds `RefreshDatabase`
to everything under `tests/Feature`, never because a test queries one.

Use `composer test:mariadb` when a compatible local MariaDB test database is
available. A parallel run creates `rateguru_test_test_<token>` databases, so the
local test user needs rights to create them. The command expects the documented non-production development
credentials; CI provides its own isolated service with those values.

## CI database matrix

The primary Pest job and the Browser job run on the pinned
`postgres:18.4-trixie` service container, the Debian image, with the
roaringbitmap extension installed from PGDG (no Alpine package exists).
Coverage uses the same image. These containers exist only
inside GitHub Actions. Separate compatibility jobs run the complete Unit and
Feature suites, fresh migrations, the standard seed, and rollback checks on
SQLite and MariaDB.

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
