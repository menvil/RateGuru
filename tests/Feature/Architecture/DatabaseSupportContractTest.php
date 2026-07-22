<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

it('documents PostgreSQL as primary with SQLite and MariaDB compatibility', function () {
    $path = base_path('docs/architecture/database-support.md');

    expect(File::exists($path))->toBeTrue();

    $contract = preg_replace('/\s+/', ' ', File::get($path));

    expect($contract)
        ->toContain('PostgreSQL 18.4 is the minimum supported primary runtime')
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

    $scripts = $composer['scripts'];

    expect($scripts['test'])->toBe([
        '@php artisan config:clear --ansi @no_additional_args',
        '@php -d memory_limit=512M vendor/bin/pest --testsuite=Unit,Feature',
    ])->and($scripts['test:postgres'])->toBe('@test')
        ->and($scripts['test:sqlite'])->toBe([
            'DB_CONNECTION=sqlite DB_DATABASE=:memory: php artisan config:clear --ansi',
            'DB_CONNECTION=sqlite DB_DATABASE=:memory: php -d memory_limit=512M vendor/bin/pest --testsuite=Unit,Feature',
        ])->and($scripts['test:mariadb'])->toBe([
            'DB_CONNECTION=mariadb DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=rateguru_test DB_USERNAME=rateguru DB_PASSWORD=rateguru php artisan config:clear --ansi',
            'DB_CONNECTION=mariadb DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=rateguru_test DB_USERNAME=rateguru DB_PASSWORD=rateguru php -d memory_limit=512M vendor/bin/pest --testsuite=Unit,Feature',
        ]);
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
    $workflowPath = base_path('.github/workflows/ci.yml');
    $workflowContents = File::get($workflowPath);
    $workflow = Yaml::parseFile($workflowPath);
    $coverage = File::get(base_path('.github/workflows/coverage.yml'));
    $downloadStep = [
        'name' => 'Download built assets',
        'uses' => 'actions/download-artifact@3e5f45b2cfb9172054b4087a40e8e0b5a5461e7c',
        'with' => [
            'name' => 'public-build',
            'path' => 'public/build',
        ],
    ];

    expect($workflowContents)
        ->toContain('- name: Run PostgreSQL tests')
        ->toContain('- name: Run SQLite compatibility tests')
        ->toContain('- name: Run MariaDB compatibility tests')
        ->and($coverage)
        ->toContain('image: postgres:18.4-alpine')
        ->toContain('extensions: mbstring, pdo_pgsql, pcov');

    foreach (['tests', 'tests-sqlite', 'migrations-mariadb'] as $job) {
        $downloadSteps = collect($workflow['jobs'][$job]['steps'])
            ->filter(fn (array $step): bool => ($step['name'] ?? null) === 'Download built assets')
            ->values();

        expect($downloadSteps)->toHaveCount(1)
            ->and($downloadSteps->first())->toBe($downloadStep);
    }

    foreach (['tests-sqlite', 'migrations-mariadb'] as $job) {
        $rollbackSteps = collect($workflow['jobs'][$job]['steps'])
            ->filter(fn (array $step): bool => ($step['id'] ?? null) === 'rollback')
            ->values();

        expect($rollbackSteps)->toHaveCount(1)
            ->and($rollbackSteps->first()['name'])->toBe('Check rollback path')
            ->and($rollbackSteps->first())->not->toHaveKey('continue-on-error');
    }
});

it('links the database support contract from the project entry points', function () {
    expect(File::get(base_path('README.md')))
        ->toContain('[Database support](docs/architecture/database-support.md)');

    expect(File::get(base_path('docs/deployment/production-environment-checklist.md')))
        ->toContain('[database support contract](../architecture/database-support.md)');
});
