<?php

it('has observability audit document', function () {
    $path = base_path('docs/observability/phase-54-observability-audit.md');

    expect(file_exists($path))->toBeTrue();

    $content = file_get_contents($path);

    expect($content)->toContain('logs');
    expect($content)->toContain('exceptions');
    expect($content)->toContain('request id');
    expect($content)->toContain('sensitive data');
});
