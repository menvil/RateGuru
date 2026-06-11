<?php

it('has observability documentation', function () {
    $path = base_path('docs/observability/observability-foundation.md');

    expect(file_exists($path))->toBeTrue();

    $content = file_get_contents($path);

    expect($content)->toContain('request id');
    expect($content)->toContain('structured logs');
    expect($content)->toContain('redaction');
    expect($content)->toContain('DomainLogger');
});
