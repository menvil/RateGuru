<?php

it('has theme documentation', function () {
    $path = base_path('docs/design/themes.md');

    expect(file_exists($path))->toBeTrue();

    $content = file_get_contents($path);

    expect($content)->toContain('Light');
    expect($content)->toContain('Dark');
    expect($content)->toContain('System');
    expect($content)->toContain('ThemeManager');
    expect($content)->toContain('data-theme');
    expect($content)->toContain('rateguru.theme.preference');
    expect($content)->toContain('ProjectSettings.default_theme');
});
