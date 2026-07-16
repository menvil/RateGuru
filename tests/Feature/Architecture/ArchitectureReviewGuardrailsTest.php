<?php

use Illuminate\Support\Facades\File;

it('provides an architecture checklist in every pull request', function () {
    $template = File::get(base_path('.github/pull_request_template.md'));

    expect($template)
        ->toContain('## Architecture Review')
        ->toContain('Presentation code delegates persistence to Actions')
        ->toContain('Resource authorization uses Policy/Gate')
        ->toContain('Reads use Eloquent or a Query Object')
        ->toContain('Raw SQL has a documented Query Object exception')
        ->toContain('No new PHPStan baseline entries');
});

it('configures coderabbit to review the enforced architecture boundaries', function () {
    $path = base_path('.coderabbit.yaml');

    expect(File::exists($path))->toBeTrue();

    $configuration = File::get($path);

    expect($configuration)
        ->toContain('schema.v2.json')
        ->toContain('app/Http/{Controllers,Requests}/**/*.php')
        ->toContain('app/{Livewire,Filament}/**/*.php')
        ->toContain('app/Queries/**/*.php')
        ->toContain('docs/architecture/http-and-database-boundaries.md');
});

it('makes architecture enforcement explicit in the phpstan ci check', function () {
    $workflow = File::get(base_path('.github/workflows/ci.yml'));

    expect($workflow)
        ->toContain('name: Architecture & static analysis (PHPStan)')
        ->toContain('php -d memory_limit=1G vendor/bin/phpstan analyse --no-progress');
});

it('retires temporary regex guards after phpstan parity', function () {
    $source = File::get(base_path('tests/Feature/Architecture/HttpAndDatabaseBoundariesTest.php'));

    expect($source)
        ->not->toContain('preg_match(')
        ->not->toContain("it('keeps inline validation")
        ->not->toContain("it('keeps unvalidated input")
        ->not->toContain("it('keeps direct query builder access")
        ->not->toContain("it('limits raw sql expressions");
});
