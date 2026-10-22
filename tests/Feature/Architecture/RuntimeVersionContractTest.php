<?php

use Illuminate\Support\Facades\File;

it('requires PHP 8.5 across Composer and every workflow', function () {
    $composer = json_decode(
        File::get(base_path('composer.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    // Composite actions count too: the deployment build's PHP version moved
    // out of the two deployment workflows and into the shared build action.
    $workflows = collect(File::files(base_path('.github/workflows')))
        ->map(fn (SplFileInfo $file): string => File::get($file->getPathname()))
        ->merge(collect(glob(base_path('.github/actions/*/action.yml')) ?: [])
            ->map(fn (string $path): string => File::get($path)))
        ->implode("\n");

    preg_match_all('/php-version:\s*[\'\"]?([^\'\"\s]+)/', $workflows, $matches);

    expect($composer['require']['php'])->toStartWith('^8.5')
        ->and(array_values(array_unique($matches[1])))->toBe(['8.5'])
        ->and(File::get(base_path('README.md')))->toContain('PHP 8.5');
});

it('keeps the PHP Composer requires at the security floor the hosts are verified against', function () {
    $composer = json_decode(File::get(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);
    $lock = json_decode(File::get(base_path('composer.lock')), true, flags: JSON_THROW_ON_ERROR);
    $floor = phpSecurityFloor();

    // Composer turns this into the platform check every request and every
    // artisan command runs first, so a build cannot boot on a PHP release
    // that the infrastructure would reject as exploitable.
    expect($composer['require']['php'])->toBe('^'.$floor)
        ->and($lock['platform']['php'])->toBe('^'.$floor)
        ->and(File::get(base_path('README.md')))->toContain('PHP '.$floor.'+')
        ->and(File::get(base_path('docs/fresh-clone-verification.md')))->toContain('PHP '.$floor.' or newer');
});

it('pins every PostgreSQL service to 18.4', function () {
    $workflows = collect(File::files(base_path('.github/workflows')))
        ->map(fn (SplFileInfo $file): string => File::get($file->getPathname()))
        ->implode("\n");

    preg_match_all('/image:\s*postgres:([^\s]+)/', $workflows, $matches);

    expect($matches[1])->not->toBeEmpty()
        ->and(array_values(array_unique($matches[1])))->toBe([
            '18.4-alpine@sha256:9a8afca54e7861fd90fab5fdf4c42477a6b1cb7d293595148e674e0a3181de15',
        ])
        ->and(File::get(base_path('docs/architecture/database-support.md')))
        ->toContain('PostgreSQL 18.4 is the minimum supported primary runtime');
});
