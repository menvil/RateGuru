<?php

use Illuminate\Support\Facades\Blade;

it('defines light and dark theme css tokens', function () {
    $css = file_get_contents(resource_path('css/theme.css'));

    expect($css)->toContain('[data-theme="dark"]');
    expect($css)->toContain('[data-theme="light"]');
    expect($css)->toContain('--rg-bg');
    expect($css)->toContain('--rg-surface');
    expect($css)->toContain('--rg-text');
    expect($css)->toContain('--rg-border');
});

it('dark theme preserves existing design tokens', function () {
    $css = file_get_contents(resource_path('css/theme.css'));

    expect($css)->toContain('--rg-accent: #a855f7');
    expect($css)->toContain('--rg-text: #e8e8ee');
});

it('light theme has distinct values from dark', function () {
    $css = file_get_contents(resource_path('css/theme.css'));

    expect($css)->toContain('--rg-bg: #f7f7fb');
    expect($css)->toContain('--rg-surface: #ffffff');
    expect($css)->toContain('--rg-border: #e2e2ec');
    expect($css)->toContain('--rg-text: #14141c');
    expect($css)->toContain('--rg-accent: #7c3aed');
});

it('fills the danger button with a red its white text reads on in every theme', function () {
    $css = file_get_contents(resource_path('css/theme.css'));

    preg_match_all('/--rg-danger-solid:\s*(#[0-9a-f]{6});/i', $css, $fills);

    // One fill beside every theme's own danger colour.
    expect($fills[1])->toHaveCount(substr_count($css, '--rg-danger:'));

    foreach ($fills[1] as $fill) {
        expect(wcagContrastRatio('#ffffff', strtolower($fill)))->toBeGreaterThanOrEqual(4.5);
    }

    expect(Blade::render('<x-danger-button>Delete account</x-danger-button>'))
        ->toContain('bg-rg-dangerSolid')
        ->toContain('text-white')
        // The plain danger red is text-bright in the dark theme, 3.8:1 under white.
        ->not->toMatch('/\bbg-rg-danger(?![A-Za-z])/');
});
