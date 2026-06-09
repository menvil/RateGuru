<?php

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
