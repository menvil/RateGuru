<?php

use App\Actions\Settings\ApplyProjectPresetAction;
use App\Exceptions\Settings\InvalidProjectPresetException;
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

// The language policy of the project ---------------------------------------

it('keeps the only language a project offers when a preset is forced over it', function () {
    [, $only] = twoTranslatedLocales();
    ProjectSettings::factory()->create(['site_name' => 'Before']);
    offerLocales([$only], $only);

    app(ApplyProjectPresetAction::class)->handle('nature', force: true);

    $settings = ProjectSettings::findOrFail(1);

    expect($settings->enabled_locales)->toBe([$only])
        ->and($settings->default_locale)->toBe($only)
        // The rest of the preset applied as usual.
        ->and($settings->site_name)->toBe('NatureGuru')
        ->and($settings->active_preset_key)->toBe('nature')
        ->and(Category::query()->active()->exists())->toBeTrue()
        ->and(app(LocaleManager::class)->projectDefault())->toBe($only);
});

it('keeps several offered languages and their default when a preset is forced over them', function () {
    [$default, $other] = twoTranslatedLocales();
    ProjectSettings::factory()->create();
    offerLocales([$default, $other], $default);
    $offered = ProjectSettings::findOrFail(1)->enabled_locales;

    app(ApplyProjectPresetAction::class)->handle('nature', force: true);

    expect(ProjectSettings::findOrFail(1))
        ->enabled_locales->toBe($offered)
        ->default_locale->toBe($default);
});

it('gives a new installation the preset default and every installed language', function () {
    expect(ProjectSettings::count())->toBe(0);

    app(ApplyProjectPresetAction::class)->handle('nature');

    $settings = ProjectSettings::findOrFail(1);
    $presetDefault = config('project_presets.nature.settings.default_locale');

    expect($settings->enabled_locales)->toBeNull()
        ->and($settings->default_locale)->toBe($presetDefault)
        ->and(app(LocaleManager::class)->isSupported($presetDefault))->toBeTrue()
        ->and(app(LocaleManager::class)->projectDefault())->toBe($presetDefault);
});

it('refuses a preset whose default language is not installed, and writes nothing', function () {
    $preset = config('project_presets.nature');
    $preset['settings']['default_locale'] = unsupportedLocale();
    config(['project_presets.unknown_locale' => $preset]);

    expect(fn () => app(ApplyProjectPresetAction::class)->handle('unknown_locale'))
        ->toThrow(InvalidProjectPresetException::class, unsupportedLocale());

    expect(ProjectSettings::count())->toBe(0)
        ->and(Category::query()->exists())->toBeFalse();
});

it('never lets a preset choose which languages are offered', function () {
    [$only] = twoTranslatedLocales();
    $preset = config('project_presets.nature');
    $preset['settings']['enabled_locales'] = [$only];
    config(['project_presets.offering' => $preset]);

    app(ApplyProjectPresetAction::class)->handle('offering');

    expect(ProjectSettings::findOrFail(1)->enabled_locales)->toBeNull();
});

it('refuses a preset whose default language a new project would not offer', function () {
    // A new installation offers the languages enabled by default; a default
    // outside them would start the project with a default nobody is offered.
    [, $notByDefault] = twoTranslatedLocales();
    config(["locales.supported.{$notByDefault}.enabled_by_default" => false]);
    $preset = config('project_presets.nature');
    $preset['settings']['default_locale'] = $notByDefault;
    config(['project_presets.not_offered' => $preset]);

    expect(fn () => app(ApplyProjectPresetAction::class)->handle('not_offered'))
        ->toThrow(InvalidProjectPresetException::class, 'enabled_by_default');

    expect(ProjectSettings::count())->toBe(0);
});
