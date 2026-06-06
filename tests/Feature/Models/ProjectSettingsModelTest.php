<?php

use App\Models\ProjectSettings;

it('can create project settings', function () {
    $settings = ProjectSettings::factory()->create([
        'site_name' => 'CatGuru',
        'feature_flags' => [
            'show_comments' => true,
        ],
    ]);

    expect($settings->site_name)->toBe('CatGuru');
    expect($settings->feature_flags['show_comments'])->toBeTrue();
});

it('creates project settings with factory defaults', function () {
    $settings = ProjectSettings::factory()->create();

    expect($settings->site_name)->toBe('RateGuru');
    expect($settings->object_singular_name)->toBe('post');
    expect($settings->object_plural_name)->toBe('posts');
    expect($settings->upload_cta_label)->toBe('Upload post');
    expect($settings->feed_title)->toBe('Latest posts');
    expect($settings->default_locale)->toBe('en');
    expect($settings->default_theme)->toBe('system');
    expect($settings->default_sort)->toBe('hot');
    expect($settings->active_preset_key)->toBe('generic');
});

it('casts feature_flags as array', function () {
    $settings = ProjectSettings::factory()->create([
        'feature_flags' => ['show_comments' => true, 'allow_user_uploads' => false],
    ]);

    expect($settings->feature_flags)->toBeArray();
    expect($settings->feature_flags['show_comments'])->toBeTrue();
    expect($settings->feature_flags['allow_user_uploads'])->toBeFalse();
});
