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
