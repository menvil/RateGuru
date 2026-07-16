<?php

use Illuminate\Support\Facades\File;

it('documents sqlite as the only supported runtime database', function () {
    $path = base_path('docs/architecture/database-support.md');

    expect(File::exists($path))->toBeTrue();

    $contract = File::get($path);

    expect($contract)
        ->toContain('SQLite is the only supported runtime database')
        ->toContain('MariaDB and PostgreSQL jobs are migration smoke checks')
        ->toContain('do not certify application query semantics');
});

it('keeps local and automated test environments on sqlite', function () {
    $environment = File::get(base_path('.env.example'));
    $phpunit = File::get(base_path('phpunit.xml'));

    expect($environment)->toMatch('/^DB_CONNECTION=sqlite$/m')
        ->and($phpunit)->toContain('<env name="DB_CONNECTION" value="sqlite"/>');
});

it('labels non-sqlite ci coverage as migration smoke checks', function () {
    $workflow = File::get(base_path('.github/workflows/ci.yml'));

    expect($workflow)
        ->toContain('- name: Run MariaDB migration smoke')
        ->toContain('- name: Run PostgreSQL migration smoke')
        ->toContain('| MariaDB migration smoke |')
        ->toContain('| PostgreSQL migration smoke |');
});

it('links the database support contract from the project entry points', function () {
    expect(File::get(base_path('README.md')))
        ->toContain('[Database support](docs/architecture/database-support.md)');

    expect(File::get(base_path('docs/deployment/production-environment-checklist.md')))
        ->toContain('[database support contract](../architecture/database-support.md)');
});
