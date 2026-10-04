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
        ->toContain('Unit and Feature suites run on all three engines')
        // And the suite that deliberately is not one of the three, with the
        // reason stated rather than left to be rediscovered.
        ->toContain('The **Architecture** suite is deliberately not one of the three')
        ->toContain('no Architecture test issues a query');
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

    // The compatibility commands carry Unit and Feature and stop there: the
    // Architecture suite has its own command, because running it against a
    // second and third engine proves nothing it could not prove against the
    // first.
    expect($scripts['test'])->toBe([
        '@php artisan config:clear --ansi @no_additional_args',
        '@php -d memory_limit=512M vendor/bin/pest --parallel --testsuite=Unit,Feature,Architecture',
    ])->and($scripts['test:postgres'])->toBe('@test')
        ->and($scripts['test:sqlite'])->toBe([
            'DB_CONNECTION=sqlite DB_DATABASE=:memory: php artisan config:clear --ansi',
            'DB_CONNECTION=sqlite DB_DATABASE=:memory: php -d memory_limit=512M vendor/bin/pest --parallel --testsuite=Unit,Feature',
        ])->and($scripts['test:mariadb'])->toBe([
            'DB_CONNECTION=mariadb DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=rateguru_test DB_USERNAME=rateguru DB_PASSWORD=rateguru php artisan config:clear --ansi',
            'DB_CONNECTION=mariadb DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=rateguru_test DB_USERNAME=rateguru DB_PASSWORD=rateguru php -d memory_limit=512M vendor/bin/pest --parallel --testsuite=Unit,Feature',
        ])->and($scripts['test:architecture'])
        ->toBe('@php -d memory_limit=512M vendor/bin/pest --parallel --testsuite=Architecture');

    // Every command runs in parallel. A sequential compatibility job was the
    // difference between a four-minute run and a forty-minute one.
    foreach (['test', 'test:sqlite', 'test:mariadb'] as $name) {
        // str_contains, not toContain: Pest reads a second argument to
        // toContain as another needle, never as a failure message.
        expect(str_contains((string) collect((array) $scripts[$name])->last(), '--parallel'))
            ->toBeTrue("{$name} must run in parallel");
    }
});

it('keeps the Architecture suite out of Feature, so it runs exactly once per engine', function () {
    // The split is the whole point: Feature is what a database engine can
    // disagree about, Architecture is what it cannot.
    $phpunit = File::get(base_path('phpunit.xml'));

    expect($phpunit)
        ->toContain('<exclude>tests/Feature/Architecture</exclude>')
        ->toContain('<testsuite name="Architecture">')
        ->toContain('<directory>tests/Feature/Architecture</directory>');

    // And nothing in that directory reaches for a database, which is what
    // makes excluding it from the compatibility engines safe rather than
    // merely faster. A test added there that needs one belongs in Feature.
    $offenders = collect(File::allFiles(base_path('tests/Feature/Architecture')))
        ->filter(fn ($file): bool => (bool) preg_match(
            '/\bRefreshDatabase\b|\bDB::|->create\(|\bfactory\(/',
            $file->getContents(),
        ))
        ->map(fn ($file): string => $file->getFilename())
        ->values()
        ->all();

    expect($offenders)->toBe([], 'these Architecture tests touch the database and would stop being covered on MariaDB and SQLite');
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
        // The Architecture suite has its own job, and the primary job says so
        // by excluding it rather than by accident of directory layout.
        ->toContain('- name: Run Architecture tests')
        ->toContain('--exclude-testsuite=Architecture')
        // A parallel run creates a database per worker; MariaDB's test user
        // only has rights on one until this widens them.
        ->toContain('- name: Allow per-worker test databases')
        ->and($coverage)
        ->toContain('image: postgres:18.4-alpine')
        ->toContain('extensions: mbstring, pdo_pgsql, pcov');

    // The Architecture job carries the same scope-guard prerequisites the
    // primary job does: without them every diff-based guard silently skips.
    $architecture = $workflow['jobs']['tests-architecture'];

    expect($architecture['env']['BASE_SHA'])->toBe('${{ github.event.pull_request.base.sha }}');
    expect(collect($architecture['steps'])->firstWhere('name', 'Checkout')['with']['fetch-depth'])->toBe(2);
    expect($architecture['services'])->toHaveKey('postgres');

    // rclone follows the suite that uses it, and is nowhere else.
    expect(collect($workflow['jobs'])
        ->filter(fn (array $job): bool => collect($job['steps'] ?? [])
            ->contains(fn (array $step): bool => ($step['name'] ?? '') === 'Install rclone'))
        ->keys()
        ->all())->toBe(['tests-architecture']);

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
