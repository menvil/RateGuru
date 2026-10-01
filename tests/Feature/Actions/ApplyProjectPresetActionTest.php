<?php

use App\Actions\Settings\ApplyProjectPresetAction;
use App\Exceptions\Settings\ProjectPresetAlreadyAppliedException;
use App\Exceptions\Settings\ProjectPresetHasContentException;
use App\Exceptions\Settings\UnknownProjectPresetException;
use App\Models\Category;
use App\Models\Post;
use App\Models\ProjectSettings;
use App\Models\RatingGroup;
use App\Models\RatingOption;
use App\Models\Tag;
use App\Models\User;
use App\Support\Locale\LocaleManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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
    $legacyCategory = Category::factory()->create(['slug' => 'legacy']);
    Tag::factory()->create(['name' => 'Legacy', 'slug' => 'legacy']);

    $result = app(ApplyProjectPresetAction::class)->handle('nature');

    $settings = ProjectSettings::firstOrFail();
    $photographerType = RatingGroup::query()->where('key', 'photographer_type')->firstOrFail();
    $shotType = RatingGroup::query()->where('key', 'shot_type')->firstOrFail();

    expect($settings->site_name)->toBe('NatureGuru')
        ->and($settings->object_singular_name)->toBe('photo')
        ->and($settings->active_preset_key)->toBe('nature')
        ->and($settings->preset_applied_at?->toDateTimeString())->toBe('2026-07-22 10:00:00')
        ->and($legacyGroup->fresh()->is_active)->toBeFalse()
        ->and($legacyOption->fresh()->is_active)->toBeFalse()
        ->and($legacyOption->fresh()->archived_at)->not->toBeNull()
        ->and($legacyCategory->fresh()->is_active)->toBeFalse()
        ->and($photographerType->is_active)->toBeTrue()
        ->and($photographerType->options()->active()->ordered()->pluck('key')->all())
        ->toBe(['professional', 'amateur'])
        ->and($shotType->is_active)->toBeTrue()
        ->and($shotType->options()->active()->count())->toBe(4)
        ->and(Category::query()->active()->ordered()->pluck('slug')->all())
        ->toBe(['landscape', 'wildlife', 'macro', 'urban'])
        ->and(Tag::query()->where('slug', 'legacy')->exists())->toBeFalse()
        ->and(Tag::query()->count())->toBe(count(config('project_presets.nature.tags')))
        ->and($result->categories)->toBe(4)
        ->and($result->deactivatedCategories)->toBe(1);
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
        ->and(RatingOption::query()->active()->count())->toBe(5)
        ->and(Category::query()->active()->count())->toBe(3);
});

it('creates the settings singleton with id one after the sequence has advanced', function () {
    $attributes = ProjectSettings::factory()->raw();
    unset($attributes['id']);
    ProjectSettings::query()->create($attributes)->delete();

    app(ApplyProjectPresetAction::class)->handle('generic');

    expect(ProjectSettings::firstOrFail()->getKey())->toBe(1);
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

it('checks setup guards inside the application transaction', function () {
    ProjectSettings::factory()->create();
    $projectSettingsQueryLevels = [];
    $baselineTransactionLevel = DB::transactionLevel();

    DB::listen(function (QueryExecuted $query) use (&$projectSettingsQueryLevels): void {
        if (str_contains($query->sql, 'project_settings')) {
            $projectSettingsQueryLevels[] = DB::transactionLevel();
        }
    });

    app(ApplyProjectPresetAction::class)->handle('nature');

    expect($projectSettingsQueryLevels)->not->toBeEmpty()
        ->and(min($projectSettingsQueryLevels))->toBeGreaterThan($baselineTransactionLevel);
});

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
    $brokenPreset['tags'] = [array_fill_keys(supportedLocales(), null)];
    config(['project_presets.broken' => $brokenPreset]);

    expect(fn () => app(ApplyProjectPresetAction::class)->handle('broken'))
        ->toThrow(QueryException::class);

    expect(ProjectSettings::firstOrFail()->site_name)->toBe('Before')
        ->and(ProjectSettings::firstOrFail()->preset_applied_at)->toBeNull()
        ->and($legacyGroup->fresh()->is_active)->toBeTrue()
        ->and(RatingGroup::query()->where('key', 'photographer_type')->exists())->toBeFalse()
        ->and(Category::query()->exists())->toBeFalse()
        ->and(Tag::query()->exists())->toBeFalse();
});

// The language policy and the static pages of the project ------------------

it('keeps the languages a project offers when a preset is forced over them', function () {
    [, $other] = twoTranslatedLocales();
    ProjectSettings::factory()->create(['site_name' => 'Before']);
    offerLocales([$other]);
    $offered = ProjectSettings::findOrFail(1)->enabled_locales;

    app(ApplyProjectPresetAction::class)->handle('nature', force: true);

    $settings = ProjectSettings::findOrFail(1);

    expect($settings->enabled_locales)->toBe($offered)
        // The rest of the preset applied as usual.
        ->and($settings->site_name)->toBe('NatureGuru')
        ->and($settings->active_preset_key)->toBe('nature')
        ->and(Category::query()->active()->exists())->toBeTrue();
});

it('gives a new installation every language enabled by default, and English as its default', function () {
    expect(ProjectSettings::count())->toBe(0);

    app(ApplyProjectPresetAction::class)->handle('nature');

    expect(ProjectSettings::findOrFail(1)->enabled_locales)->toBeNull()
        ->and(app(LocaleManager::class)->enabledCodes())->toBe(app(LocaleManager::class)->enabledByDefault())
        ->and(app(LocaleManager::class)->default())->toBe('en');
});

it('never lets a preset choose the languages offered or the default', function () {
    // A custom preset written for the older shape may still name a default.
    [$only] = twoTranslatedLocales();
    $preset = config('project_presets.nature');
    $preset['settings']['enabled_locales'] = [$only];
    $preset['settings']['default_locale'] = $only;
    config(['project_presets.offering' => $preset]);

    app(ApplyProjectPresetAction::class)->handle('offering');

    expect(ProjectSettings::findOrFail(1)->enabled_locales)->toBeNull()
        ->and(app(LocaleManager::class)->default())->toBe('en');
});

it('gives a new installation a copy of every static page in every language the repository ships', function () {
    app(ApplyProjectPresetAction::class)->handle('nature');

    expect(ProjectSettings::findOrFail(1)->static_pages)->toBe(config('static-pages.defaults'));
});

it('leaves the static pages of an existing project as they are when a preset is forced over it', function () {
    $pages = config('static-pages.defaults');
    $pages['about']['en']['title'] = 'About us, as the project wrote it';
    ProjectSettings::factory()->create(['static_pages' => $pages]);

    app(ApplyProjectPresetAction::class)->handle('nature', force: true);

    expect(ProjectSettings::findOrFail(1)->static_pages)->toBe($pages);
});
