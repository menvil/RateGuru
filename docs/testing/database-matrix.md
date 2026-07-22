# Database test matrix

PostgreSQL 18.4 is the default test database. Start the Homebrew service, then
use the normal test command:

```bash
brew services start postgresql@18
composer test
```

The database names are deliberately separate:

- `rateguru` is local development data;
- `rateguru_test` is disposable automated-test data.

## Compatibility commands

SQLite needs no service and is the fastest fallback:

```bash
composer test:sqlite
```

MariaDB compatibility expects a local `rateguru_test` database and the local
credentials declared by the Composer script:

```bash
composer test:mariadb
```

Never point a test command at staging or production. `RefreshDatabase` and
parallel testing are allowed to rebuild the configured test database.

## CI coverage

CI runs Pest and Browser tests on PostgreSQL. SQLite and MariaDB each run the
complete Unit and Feature suites plus migration, seed, and rollback checks.
Coverage collection runs on PostgreSQL.
