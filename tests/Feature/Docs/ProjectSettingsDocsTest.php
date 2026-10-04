<?php

it('has project settings documentation', function () {
    $path = base_path('docs/admin/project-settings.md');

    expect(file_exists($path))->toBeTrue();

    $content = file_get_contents($path);

    expect($content)->toContain('Project Settings');
    expect($content)->toContain('feature flags');
    expect($content)->toContain('ProjectSettingsManager');
    expect($content)->toContain('preset_applied_at');
    expect($content)->toContain('read-only');
});
