# RateGuru

RateGuru is a Laravel application for community-driven ratings and decision support.

## Stack

- Laravel
- Livewire
- Alpine.js
- Filament
- SQLite
- Pest / PHPUnit
- Tailwind CSS

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

Create the SQLite database and run migrations:

```bash
touch database/database.sqlite
php artisan migrate
```

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

## Branch Strategy

RateGuru uses a GitFlow-lite workflow:

- `main`: production-ready code.
- `develop`: integration branch for completed work.
- `feature/*`: one task per feature branch.
- `release/*`: release stabilization branches.
- `hotfix/*`: urgent production fixes.

## Agent Rules

Development agents must follow the project rules in [AGENTS.md](AGENTS.md).
