<?php

it('has seed data documentation', function () {
    expect(file_exists(base_path('docs/dev/seed-data.md')))->toBeTrue();
});

it('documents seed commands and demo credentials warning', function () {
    $docs = file_get_contents(base_path('docs/dev/seed-data.md'));

    expect($docs)->toContain('php artisan migrate:fresh --seed');
    expect($docs)->toContain('admin@rateguru.test');
    expect($docs)->toContain('moderator@rateguru.test');
    expect($docs)->toContain('local-only');
});

it('documents the separate large demo dataset and safe rerun workflow', function () {
    $docs = file_get_contents(base_path('docs/dev/seed-data.md'));

    expect($docs)->toContain('php artisan db:seed --class=DemoFillSeeder');
    expect($docs)->toContain('500 users');
    expect($docs)->toContain('100 posts');
    expect($docs)->toContain('migrate:fresh --seed');
    expect($docs)->toContain('rebuilds');
    expect($docs)->toContain('generated interactions and media');
});
