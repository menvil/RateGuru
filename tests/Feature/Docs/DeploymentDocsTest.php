<?php

it('has production environment checklist', function () {
    $path = base_path('docs/deployment/production-environment-checklist.md');

    expect(file_exists($path))->toBeTrue();

    $content = file_get_contents($path);

    expect($content)->toContain('APP_ENV=production');
    expect($content)->toContain('APP_DEBUG=false');
    expect($content)->toContain('APP_KEY');
    expect($content)->toContain('backup');
    expect($content)->toContain('php artisan migrate --force');
});

it('does not recommend migrate fresh for production', function () {
    $content = file_get_contents(base_path('docs/deployment/production-environment-checklist.md'));

    expect($content)->not->toContain('migrate:fresh in production');
});

it('links production checklist from deployment readme', function () {
    $content = file_get_contents(base_path('docs/deployment/README.md'));

    expect($content)->toContain('production-environment-checklist.md');
});
