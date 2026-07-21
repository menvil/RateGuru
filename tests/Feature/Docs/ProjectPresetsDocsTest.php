<?php

it('has project presets documentation', function () {
    $path = base_path('docs/admin/project-presets.md');

    expect(file_exists($path))->toBeTrue();

    $content = file_get_contents($path);

    expect($content)->toContain('generic');
    expect($content)->toContain('nature');
    expect($content)->toContain('ai_images');
    expect($content)->toContain('breasts');
    expect($content)->toContain('config/project_presets.php');
});
