<?php

it('has external observability integration notes', function () {
    $path = base_path('docs/observability/external-integrations.md');

    expect(file_exists($path))->toBeTrue();

    $content = file_get_contents($path);

    expect($content)->toContain('Sentry');
    expect($content)->toContain('Datadog');
    expect($content)->toContain('Nightwatch');
    expect($content)->toContain('not required');
});
