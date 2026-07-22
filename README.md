# RateGuru

RateGuru is a Laravel application for community-driven ratings and decision support.

## Stack

- Laravel
- Livewire
- Alpine.js
- Filament
- PostgreSQL (primary), SQLite and MariaDB (compatible runtimes)
- Pest / PHPUnit
- Tailwind CSS

See the [Database support](docs/architecture/database-support.md) contract for
the development commands and the three-engine CI compatibility matrix.

## Local Setup

Clone the repository and install PHP dependencies:

```bash
git clone git@github.com:menvil/rateguru.git
cd rateguru
composer install
```

Create the local environment file and application key:

```bash
cp .env.example .env
php artisan key:generate
```

Install and start PostgreSQL 18.4 through Homebrew:

```bash
brew install postgresql@18
brew services start postgresql@18
```

Create the non-production role and the separate development and test databases
once per machine:

```bash
createuser --createdb --login rateguru
psql postgres -c "ALTER ROLE rateguru PASSWORD 'rateguru';"
createdb --owner=rateguru rateguru
createdb --owner=rateguru rateguru_test
```

Then run migrations:

```bash
php artisan migrate
```

RateGuru does not require Docker for local development. Laravel, frontend tools,
and PostgreSQL run directly on the host. GitHub Actions uses its own isolated
PostgreSQL container. For a quick test run without PostgreSQL, use
`composer test:sqlite`.

For a fresh local database with deterministic demo data, see
[docs/dev/seed-data.md](docs/dev/seed-data.md).

Install and build frontend assets:

```bash
npm install
npm run build
```

Start the local development server:

```bash
php artisan serve
```

## Tests

Run the application test suite:

```bash
composer test
```

`composer test` and `composer test:postgres` use PostgreSQL. Compatibility
commands are `composer test:sqlite` and `composer test:mariadb`.

## Branch Strategy

RateGuru uses a GitFlow-lite workflow:

- `main`: production-ready code.
- `develop`: integration branch for completed work.
- `feature/*`: one task per feature branch.
- `release/*`: release stabilization branches.
- `hotfix/*`: urgent production fixes.

## Agent Rules

Development agents must follow the project rules in [AGENTS.md](AGENTS.md).
