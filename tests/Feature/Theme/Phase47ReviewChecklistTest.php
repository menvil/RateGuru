<?php

it('has phase 47 theme review checklist', function () {
    $path = base_path('docs/design/phase-47-theme-review.md');

    expect(file_exists($path))->toBeTrue();

    $content = file_get_contents($path);

    expect($content)->toContain('ThemeManager');
    expect($content)->toContain('light');
    expect($content)->toContain('dark');
    expect($content)->toContain('system');
    expect($content)->toContain('raw color');
    expect($content)->toContain('localStorage');
    expect($content)->toContain('data-theme');
});
