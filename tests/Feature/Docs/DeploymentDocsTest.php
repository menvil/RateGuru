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

it('has storage symlink deployment docs', function () {
    $path = base_path('docs/deployment/storage-symlink.md');

    expect(file_exists($path))->toBeTrue();

    $content = file_get_contents($path);

    expect($content)->toContain('php artisan storage:link');
    expect($content)->toContain('storage/app/public');
    expect($content)->toContain('public/storage');
    expect($content)->toContain('Permissions');
    expect($content)->toContain('Do not chmod 777 blindly');
});

it('links storage symlink docs from deployment readme', function () {
    $content = file_get_contents(base_path('docs/deployment/README.md'));

    expect($content)->toContain('storage-symlink.md');
});

it('has queue worker deployment docs', function () {
    $path = base_path('docs/deployment/queue-worker.md');

    expect(file_exists($path))->toBeTrue();

    $content = file_get_contents($path);

    expect($content)->toContain('QUEUE_CONNECTION');
    expect($content)->toContain('php artisan queue:work');
    expect($content)->toContain('sync');
    expect($content)->toContain('Redis is not required');
    expect($content)->toContain('php artisan queue:restart');
});
