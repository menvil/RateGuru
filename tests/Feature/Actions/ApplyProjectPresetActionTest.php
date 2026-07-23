<?php

use App\Actions\Settings\ApplyProjectPresetAction;
use App\Exceptions\Settings\ProjectPresetAlreadyAppliedException;
use App\Exceptions\Settings\ProjectPresetHasContentException;
use App\Exceptions\Settings\UnknownProjectPresetException;
use App\Models\Post;
use App\Models\ProjectSettings;
use App\Models\RatingGroup;
use App\Models\RatingOption;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow('2026-07-22 10:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

it('applies the complete project preset atomically', function () {
    ProjectSettings::factory()->create([
        'site_name' => 'RateGuru',
    ]);
    $legacyGroup = RatingGroup::factory()->create(['key' => 'legacy']);
    $legacyOption = RatingOption::factory()->for($legacyGroup, 'group')->create(['key' => 'legacy_option']);
    Tag::factory()->create(['name' => 'Legacy', 'slug' => 'legacy']);

    app(ApplyProjectPresetAction::class)->handle('nature');

    $settings = ProjectSettings::firstOrFail();
    $source = RatingGroup::query()->where('key', 'source')->firstOrFail();
    $category = RatingGroup::query()->where('key', 'category')->firstOrFail();

    expect($settings->site_name)->toBe('NatureGuru')
        ->and($settings->object_singular_name)->toBe('photo')
        ->and($settings->active_preset_key)->toBe('nature')
        ->and($settings->preset_applied_at?->toDateTimeString())->toBe('2026-07-22 10:00:00')
        ->and($legacyGroup->fresh()->is_active)->toBeFalse()
        ->and($legacyOption->fresh()->is_active)->toBeFalse()
        ->and($legacyOption->fresh()->archived_at)->not->toBeNull()
        ->and($source->is_active)->toBeTrue()
        ->and($source->options()->active()->ordered()->pluck('key')->all())
        ->toBe(['professional', 'amateur'])
        ->and($category->is_active)->toBeTrue()
        ->and($category->options()->active()->count())->toBe(8)
        ->and(Tag::query()->where('slug', 'legacy')->exists())->toBeFalse()
        ->and(Tag::query()->count())->toBe(count(config('project_presets.nature.tags')));
});

it('creates settings row when missing and applies preset', function () {
    expect(ProjectSettings::count())->toBe(0);

    app(ApplyProjectPresetAction::class)->handle('ai_images');

    $settings = ProjectSettings::first();

    expect($settings)->not->toBeNull();
    expect($settings->site_name)->toBe('AIGuru');
    expect($settings->active_preset_key)->toBe('ai_images');
    expect($settings->preset_applied_at)->not->toBeNull();
});

it('creates a usable generic rating configuration on an empty project', function () {
    app(ApplyProjectPresetAction::class)->handle('generic');

    expect(RatingGroup::query()->active()->count())->toBe(2)
        ->and(RatingOption::query()->active()->count())->toBe(5);
});

it('fails for unknown project preset', function () {
    app(ApplyProjectPresetAction::class)->handle('unknown');
})->throws(UnknownProjectPresetException::class);

it('refuses to replace an already applied preset', function () {
    ProjectSettings::factory()->create([
        'active_preset_key' => 'nature',
        'preset_applied_at' => now()->subDay(),
    ]);

    app(ApplyProjectPresetAction::class)->handle('ai_images');
})->throws(ProjectPresetAlreadyAppliedException::class);

it('refuses to apply a preset when content already exists', function () {
    Post::factory()->create();

    app(ApplyProjectPresetAction::class)->handle('nature');
})->throws(ProjectPresetHasContentException::class);

it('allows an explicit forced reapplication without deleting posts or users', function () {
    $user = User::factory()->create();
    $post = Post::factory()->create(['user_id' => $user->id]);
    ProjectSettings::factory()->create([
        'active_preset_key' => 'nature',
        'preset_applied_at' => now()->subDay(),
    ]);

    app(ApplyProjectPresetAction::class)->handle('ai_images', force: true);

    expect(User::query()->whereKey($user->id)->exists())->toBeTrue()
        ->and(Post::query()->whereKey($post->id)->exists())->toBeTrue()
        ->and(ProjectSettings::firstOrFail()->active_preset_key)->toBe('ai_images');
});

it('rolls back every preset change when one part fails', function () {
    ProjectSettings::factory()->create(['site_name' => 'Before']);
    $legacyGroup = RatingGroup::factory()->create(['key' => 'legacy']);

    $brokenPreset = config('project_presets.nature');
    $brokenPreset['tags'] = [['en' => null, 'ru' => null, 'bg' => null]];
    config(['project_presets.broken' => $brokenPreset]);

    expect(fn () => app(ApplyProjectPresetAction::class)->handle('broken'))
        ->toThrow(QueryException::class);

    expect(ProjectSettings::firstOrFail()->site_name)->toBe('Before')
        ->and(ProjectSettings::firstOrFail()->preset_applied_at)->toBeNull()
        ->and($legacyGroup->fresh()->is_active)->toBeTrue()
        ->and(RatingGroup::query()->where('key', 'source')->exists())->toBeFalse()
        ->and(Tag::query()->exists())->toBeFalse();
});
