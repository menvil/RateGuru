<?php

use App\Actions\Settings\ApplyProjectPresetAction;
use App\Exceptions\Settings\UnknownProjectPresetException;
use App\Models\ProjectSettings;

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

    app(ApplyProjectPresetAction::class)->handle('food');

    $settings = ProjectSettings::first();

    expect($settings)->not->toBeNull();
    expect($settings->site_name)->toBe('FoodGuru');
    expect($settings->active_preset_key)->toBe('food');
});

it('fails for unknown project preset', function () {
    app(ApplyProjectPresetAction::class)->handle('unknown');
})->throws(UnknownProjectPresetException::class);

it('does not touch posts or users when applying preset', function () {
    $user = App\Models\User::factory()->create();
    $post = App\Models\Post::factory()->create(['user_id' => $user->id]);

    app(ApplyProjectPresetAction::class)->handle('nature');

    expect(App\Models\User::count())->toBe(1);
    expect(App\Models\Post::count())->toBe(1);
});
