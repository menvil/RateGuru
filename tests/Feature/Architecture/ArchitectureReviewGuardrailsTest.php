<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

it('provides an architecture checklist in every pull request', function () {
    $template = File::get(base_path('.github/pull_request_template.md'));

    expect($template)
        ->toContain('## Architecture Review')
        ->toContain('Presentation code delegates persistence to Actions')
        ->toContain('Resource authorization uses Policy/Gate')
        ->toContain('Reads use Eloquent or a Query Object')
        ->toContain('Raw SQL has a documented Query Object exception')
        ->toContain('PHPStan remains baseline-free');
});

it('configures coderabbit to review the enforced architecture boundaries', function () {
    $path = base_path('.coderabbit.yaml');

    expect(File::exists($path))->toBeTrue();

    $configuration = File::get($path);
    $parsedConfiguration = Yaml::parse($configuration);

    expect($configuration)
        ->toContain('schema.v2.json')
        ->toContain('app/Http/{Controllers,Requests}/**/*.php')
        ->toContain('app/{Livewire,Filament}/**/*.php')
        ->toContain('app/Queries/**/*.php')
        ->toContain('docs/architecture/http-and-database-boundaries.md');

    expect(data_get($parsedConfiguration, 'reviews.auto_review.base_branches'))
        ->toBeArray()
        ->toContain('main');
});

it('makes architecture enforcement explicit in the phpstan ci check', function () {
    $workflow = File::get(base_path('.github/workflows/ci.yml'));

    expect($workflow)
        ->toContain('name: Architecture & static analysis (PHPStan)')
        ->toContain('composer analyse:architecture')
        ->toContain('composer analyse')
        ->toContain('baseline-findings')
        ->toContain('PHPStan suppressions')
        ->toContain('Architecture findings in baseline');
});

it('runs architecture rules without the legacy phpstan baseline', function () {
    $configuration = File::get(base_path('tools/phpstan/architecture-only.neon'));

    expect($configuration)
        ->toContain('customRulesetUsed: true')
        ->toContain('tools/phpstan/architecture.neon')
        ->not->toContain('phpstan-baseline.neon');

    $composer = File::get(base_path('composer.json'));

    expect($composer)
        ->toContain('"analyse:architecture"')
        ->toContain('tools/phpstan/architecture-only.neon')
        ->toContain('"baseline:report"')
        ->toContain('"baseline:guard"');
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

it('keeps phpstan baseline free after retiring all suppressions', function () {
    expect(File::exists(base_path('phpstan-baseline.neon')))->toBeFalse();

    $configuration = File::get(base_path('phpstan.neon'));

    expect($configuration)->not->toContain('phpstan-baseline.neon');
});
