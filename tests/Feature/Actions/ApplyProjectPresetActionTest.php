<?php

use App\Actions\Settings\ApplyProjectPresetAction;
use App\Exceptions\Settings\UnknownProjectPresetException;
use App\Models\Post;
use App\Models\ProjectSettings;
use App\Models\User;

it('applies project preset to settings', function () {
    ProjectSettings::factory()->create([
        'site_name' => 'RateGuru',
    ]);

    app(ApplyProjectPresetAction::class)->handle('nature');

    $settings = ProjectSettings::first();

    expect($settings->site_name)->toBe('NatureGuru');
    expect($settings->object_singular_name)->toBe('photo');
    expect($settings->active_preset_key)->toBe('nature');
});

it('creates settings row when missing and applies preset', function () {
    expect(ProjectSettings::count())->toBe(0);

    app(ApplyProjectPresetAction::class)->handle('ai_images');

    $settings = ProjectSettings::first();

    expect($settings)->not->toBeNull();
    expect($settings->site_name)->toBe('AIGuru');
    expect($settings->active_preset_key)->toBe('ai_images');
});

it('fails for unknown project preset', function () {
    app(ApplyProjectPresetAction::class)->handle('unknown');
})->throws(UnknownProjectPresetException::class);

it('does not touch posts or users when applying preset', function () {
    $user = User::factory()->create();
    $post = Post::factory()->create(['user_id' => $user->id]);

    app(ApplyProjectPresetAction::class)->handle('nature');

    expect(User::count())->toBe(1);
    expect(Post::count())->toBe(1);
});
