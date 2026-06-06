<?php

it('has phase 45 project settings review checklist', function () {
    $path = base_path('docs/admin/phase-45-project-settings-review.md');

    expect(file_exists($path))->toBeTrue();

    $content = file_get_contents($path);

    expect($content)->toContain('ProjectSettings');
    expect($content)->toContain('ProjectSettingsManager');
    expect($content)->toContain('ApplyProjectPresetAction');
    expect($content)->toContain('feature flags');
});
