<?php

it('has phase 54 observability review checklist', function () {
    $path = base_path('docs/observability/phase-54-observability-review.md');

    expect(file_exists($path))->toBeTrue();

    $content = file_get_contents($path);

    expect($content)->toContain('request id');
    expect($content)->toContain('DomainLogger');
    expect($content)->toContain('SensitiveDataRedactor');
    expect($content)->toContain('Sentry');
});
