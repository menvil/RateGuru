<?php

use Illuminate\Support\Facades\Blade;

it('renders skeleton with pulse animation classes', function () {
    $html = Blade::render(<<<'BLADE'
        <x-ui.skeleton />
    BLADE);

    expect($html)
        ->toContain('animate-pulse')
        ->toContain('bg-white/10')
        ->toContain('motion-safe:transition-opacity');
});

it('supports line, block, and circle shapes', function (string $shape, string $expectedClass) {
    $html = Blade::render(<<<BLADE
        <x-ui.skeleton shape="{$shape}" />
    BLADE);

    expect($html)->toContain($expectedClass);
})->with([
    'line' => ['line', 'h-3'],
    'block' => ['block', 'h-24'],
    'circle' => ['circle', 'rounded-full'],
]);

it('supports custom width and height', function () {
    $html = Blade::render(<<<'BLADE'
        <x-ui.skeleton shape="block" width="w-32" height="h-12" />
    BLADE);

    expect($html)
        ->toContain('w-32')
        ->toContain('h-12');
});
