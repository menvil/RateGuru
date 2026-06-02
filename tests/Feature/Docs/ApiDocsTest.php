<?php

it('has api auth strategy note', function () {
    $path = base_path('docs/api/auth-strategy.md');

    expect(file_exists($path))->toBeTrue();

    $content = file_get_contents($path);

    expect($content)->toContain('API Auth Strategy');
    expect($content)->toContain('public read');
    expect($content)->toContain('write endpoints');
    expect($content)->toContain('Sanctum');
    expect($content)->toContain('not implement API auth');
});

it('links api auth strategy from api readme', function () {
    $content = file_get_contents(base_path('docs/api/README.md'));

    expect($content)->toContain('auth-strategy.md');
});

it('has api versioning note', function () {
    $path = base_path('docs/api/versioning.md');

    expect(file_exists($path))->toBeTrue();

    $content = file_get_contents($path);

    expect($content)->toContain('API Versioning');
    expect($content)->toContain('/api/v1');
    expect($content)->toContain('backward compatibility');
    expect($content)->toContain('Not implemented in Phase 40');
});

it('links api readiness docs from api readme', function () {
    $content = file_get_contents(base_path('docs/api/README.md'));

    expect($content)->toContain('auth-strategy.md');
    expect($content)->toContain('versioning.md');
});

it('has phase 40 api readiness review', function () {
    $path = base_path('docs/api/phase-40-api-readiness-review.md');

    expect(file_exists($path))->toBeTrue();

    $content = file_get_contents($path);

    expect($content)->toContain('PostResource shape is tested');
    expect($content)->toContain('No public API endpoints are exposed');
});
