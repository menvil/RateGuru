<?php

use Illuminate\Support\Facades\File;

it('documents PostgreSQL as primary with SQLite and MariaDB compatibility', function () {
    $path = base_path('docs/architecture/database-support.md');

    expect(File::exists($path))->toBeTrue();

    $contract = preg_replace('/\s+/', ' ', File::get($path));

    expect($contract)
        ->toContain('PostgreSQL is the primary runtime database')
        ->toContain('SQLite and MariaDB are supported compatibility targets')
        ->toContain('Unit and Feature suites run on all three engines');
});

it('uses PostgreSQL by default for local development and automated tests', function () {
    $environment = File::get(base_path('.env.example'));
    $phpunit = File::get(base_path('phpunit.xml'));

    expect($environment)->toMatch('/^DB_CONNECTION=pgsql$/m')
        ->and($phpunit)->toContain('<env name="DB_CONNECTION" value="pgsql"/>')
        ->and($phpunit)->toContain('<env name="DB_DATABASE" value="rateguru_test"/>');
});

it('provides explicit test commands for every supported database', function () {
    $composer = json_decode(
        File::get(base_path('composer.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($composer['scripts'])
        ->toHaveKeys(['test', 'test:postgres', 'test:sqlite', 'test:mariadb']);
});

it('uses host PostgreSQL for local development without Docker Compose', function () {
    $composer = json_decode(
        File::get(base_path('composer.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect(File::exists(base_path('compose.yaml')))->toBeFalse()
        ->and(File::exists(base_path('infrastructure/local/postgres/ensure-test-database.sh')))->toBeFalse()
        ->and($composer['scripts'])->not->toHaveKeys(['db:start', 'db:stop'])
        ->and($composer['scripts']['setup'])->not->toContain('@db:start')
        ->and(File::get(base_path('README.md')))
        ->toContain('brew install postgresql@18')
        ->toContain('brew services start postgresql@18');
});

it('runs primary and compatibility test suites in ci', function () {
    $workflow = File::get(base_path('.github/workflows/ci.yml'));
    $coverage = File::get(base_path('.github/workflows/coverage.yml'));

    expect($workflow)
        ->toContain('- name: Run PostgreSQL tests')
        ->toContain('- name: Run SQLite compatibility tests')
        ->toContain('- name: Run MariaDB compatibility tests')
        ->and(substr_count($workflow, '- name: Download built assets'))
        ->toBe(3)
        ->and($coverage)
        ->toContain('image: postgres:18.4-alpine')
        ->toContain('extensions: mbstring, pdo_pgsql, pcov');
});

it('links the database support contract from the project entry points', function () {
    expect(File::get(base_path('README.md')))
        ->toContain('[Database support](docs/architecture/database-support.md)');

    expect(File::get(base_path('docs/deployment/production-environment-checklist.md')))
        ->toContain('[database support contract](../architecture/database-support.md)');
});
