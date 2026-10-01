<?php

use App\Actions\Settings\ApplyProjectPresetAction;
use App\Actions\Translations\BackfillProjectTranslationsAction;
use App\Models\Category;
use App\Models\ProjectSettings;
use App\Models\RatingGroup;
use App\Models\RatingOption;
use App\Models\Tag;
use App\Support\Locale\LocaleManager;
use App\Support\Settings\PresetSettingsBuilder;
use App\Support\Translations\TranslationBackfillReport;
use Illuminate\Support\Str;

/**
 * The safe backfill: it adds a language's missing translations for content the
 * project still shows as the repository wrote it, and touches nothing else.
 *
 * Each case starts from a project set up with a preset, with one installed
 * language taken back out of the database — the state of a project the day a
 * release adds a language it was set up before.
 */
function backfill(): TranslationBackfillReport
{
    return app(BackfillProjectTranslationsAction::class)->handle();
}

/** Removes one language from every translation the project stores. */
function forgetLanguage(string $locale): void
{
    $strip = fn (mixed $translations): mixed => is_array($translations) ? array_diff_key($translations, [$locale => true]) : $translations;

    $settings = ProjectSettings::findOrFail(1);

    foreach (PresetSettingsBuilder::TRANSLATABLE as $field) {
        $settings->setAttribute("{$field}_translations", $strip($settings->getAttribute("{$field}_translations")));
    }

    $settings->static_pages = collect($settings->static_pages ?? [])->map(fn (array $page): array => array_diff_key($page, [$locale => true]))->all();
    $settings->save();

    foreach ([Category::class => ['name'], Tag::class => ['name'], RatingGroup::class => ['label', 'description'], RatingOption::class => ['label', 'description']] as $model => $fields) {
        foreach ($model::all() as $row) {
            foreach ($fields as $field) {
                $row->setAttribute("{$field}_translations", $strip($row->getAttribute("{$field}_translations")));
            }

            $row->save();
        }
    }
}

/** Every stored translation, to prove a run changed nothing. */
function storedTranslations(): array
{
    return [
        ProjectSettings::findOrFail(1)->only([...array_map(fn (string $field): string => "{$field}_translations", PresetSettingsBuilder::TRANSLATABLE), 'static_pages']),
        Category::query()->orderBy('id')->get(['name_translations'])->toArray(),
        Tag::query()->orderBy('id')->get(['name_translations'])->toArray(),
        RatingGroup::query()->orderBy('id')->get(['label_translations', 'description_translations'])->toArray(),
        RatingOption::query()->orderBy('id')->get(['label_translations', 'description_translations'])->toArray(),
    ];
}

function presetValue(string $path, string $locale): string
{
    return config("project_presets.nature.{$path}.{$locale}");
}

beforeEach(function () {
    [$this->target] = twoTranslatedLocales();

    app(ApplyProjectPresetAction::class)->handle('nature');
    ProjectSettings::query()->update(['static_pages' => json_encode(config('static-pages.defaults'))]);
    forgetLanguage($this->target);
});

it('fills the language back in for content the project has not changed', function () {
    $report = backfill();
    $target = $this->target;

    expect($report->filled)->toBeGreaterThan(0)
        ->and($report->skippedCustomized)->toBe(0)
        ->and(ProjectSettings::findOrFail(1)->site_name_translations[$target])->toBe(presetValue('settings.site_name', $target))
        ->and(ProjectSettings::findOrFail(1)->static_pages['about'][$target]['title'])->toBe(config("static-pages.defaults.about.{$target}.title"))
        ->and(Category::query()->where('slug', 'landscape')->sole()->name_translations[$target])->toBe(presetValue('categories.0.name', $target))
        ->and(RatingGroup::query()->where('key', 'photographer_type')->sole()->label_translations[$target])->toBe(presetValue('rating_groups.0.label', $target))
        ->and(RatingGroup::query()->where('key', 'photographer_type')->sole()->description_translations[$target])->toBe(presetValue('rating_groups.0.description', $target))
        ->and(RatingOption::query()->where('key', 'professional')->sole()->label_translations[$target])->toBe(presetValue('rating_groups.0.options.0.label', $target))
        ->and(Tag::query()->where('slug', 'sunrise')->sole()->name_translations[$target])->toBe(presetValue('tags.0', $target));
});

it('finds nothing more to do on a second run', function () {
    expect(backfill()->filled)->toBeGreaterThan(0);

    $after = storedTranslations();
    $second = backfill();

    expect($second->filled)->toBe(0)
        ->and($second->skippedCustomized)->toBe(0)
        ->and(storedTranslations())->toBe($after);
});

it('never overwrites a translation that is already there', function () {
    $category = Category::query()->where('slug', 'landscape')->sole();
    $category->update(['name_translations' => [...$category->name_translations, $this->target => 'An administrator wrote this']]);

    backfill();

    expect($category->fresh()->name_translations[$this->target])->toBe('An administrator wrote this');
});

it('leaves a field alone once the project changed the text it translates', function () {
    Category::query()->where('slug', 'landscape')->update(['name' => 'Landscapes & seascapes']);
    ProjectSettings::query()->update(['site_name' => 'TitsGuru']);

    $report = backfill();

    expect(Category::query()->where('slug', 'landscape')->sole()->name_translations)->not->toHaveKey($this->target)
        ->and(ProjectSettings::findOrFail(1)->site_name_translations)->not->toHaveKey($this->target)
        // The rest of the settings row still gets its translations.
        ->and(ProjectSettings::findOrFail(1)->feed_title_translations[$this->target])->toBe(presetValue('settings.feed_title', $this->target))
        ->and($report->skippedCustomized)->toBe(2);
});

it('decides field by field, not row by row', function () {
    RatingGroup::query()->where('key', 'photographer_type')->update(['description' => 'An administrator rewrote this description.']);

    backfill();

    $group = RatingGroup::query()->where('key', 'photographer_type')->sole();

    expect($group->label_translations[$this->target])->toBe(presetValue('rating_groups.0.label', $this->target))
        ->and($group->description_translations)->not->toHaveKey($this->target);
});

it('never creates content the project does not have', function () {
    Category::query()->where('slug', 'landscape')->delete();
    Tag::query()->where('slug', 'sunrise')->delete();
    RatingOption::query()->where('key', 'professional')->delete();

    $report = backfill();

    expect(Category::query()->where('slug', 'landscape')->exists())->toBeFalse()
        ->and(Tag::query()->where('slug', 'sunrise')->exists())->toBeFalse()
        ->and(RatingOption::query()->where('key', 'professional')->exists())->toBeFalse()
        ->and($report->skippedUnknown)->toBeGreaterThanOrEqual(3);
});

it('leaves content an administrator created out of it', function () {
    $own = Category::factory()->create(['slug' => 'georgian-food', 'name' => 'Georgian food', 'name_translations' => null]);
    $ownTag = Tag::factory()->create(['slug' => 'blonde', 'name' => 'Blonde', 'name_translations' => null]);

    backfill();

    expect($own->fresh()->name_translations)->toBeNull()
        ->and($ownTag->fresh()->name_translations)->toBeNull();
});

it('finds an option by its group and its key together', function () {
    // The same option key in a group the preset does not have is someone else's.
    $other = RatingGroup::factory()->create(['key' => 'unrelated', 'label' => 'Unrelated']);
    $twin = RatingOption::factory()->for($other, 'group')->create(['key' => 'professional', 'label' => 'Professional', 'label_translations' => null]);

    backfill();

    expect($twin->fresh()->label_translations)->toBeNull()
        ->and(RatingOption::query()->whereBelongsTo(RatingGroup::query()->where('key', 'photographer_type')->sole(), 'group')->where('key', 'professional')->sole()->label_translations[$this->target])
        ->toBe(presetValue('rating_groups.0.options.0.label', $this->target));
});

it('finds a tag by the slug of its English name, as preset application does', function () {
    $tag = Tag::query()->where('slug', Str::slug(presetValue('tags.0', 'en')))->sole();

    backfill();

    expect($tag->fresh()->name_translations[$this->target])->toBe(presetValue('tags.0', $this->target));
});

it('fills a static page the project saved but did not rewrite, and skips one it rewrote', function () {
    $pages = ProjectSettings::findOrFail(1)->static_pages;
    $pages['terms']['en']['content'] = 'Our own terms.';
    ProjectSettings::query()->update(['static_pages' => json_encode($pages)]);

    $report = backfill();
    $stored = ProjectSettings::findOrFail(1)->static_pages;

    expect($stored['about'][$this->target]['title'])->toBe(config("static-pages.defaults.about.{$this->target}.title"))
        ->and($stored['terms'][$this->target] ?? [])->not->toHaveKey('content')
        ->and($report->skippedCustomized)->toBe(1);
});

it('writes nothing for a static page the project never saved', function () {
    ProjectSettings::query()->update(['static_pages' => null]);

    backfill();

    expect(ProjectSettings::findOrFail(1)->static_pages)->toBeNull();
});

it('fills a language the project does not offer yet', function () {
    offerLocales(array_values(array_diff(supportedLocales(), [$this->target])), 'en');

    backfill();

    expect(Category::query()->where('slug', 'landscape')->sole()->name_translations[$this->target])
        ->toBe(presetValue('categories.0.name', $this->target));
});

it('takes nothing from a preset the project was not set up with', function () {
    ProjectSettings::query()->update(['active_preset_key' => 'no_such_preset']);

    backfill();

    expect(Category::query()->where('slug', 'landscape')->sole()->name_translations)->not->toHaveKey($this->target);
});

it('reports its counts from the deploy command', function () {
    $this->artisan('rateguru:translations:backfill')
        ->expectsOutputToContain('Translation backfill: filled')
        ->assertExitCode(0);

    $this->artisan('rateguru:translations:backfill')
        ->expectsOutputToContain('Translation backfill: filled 0,')
        ->assertExitCode(0);
});

it('lets a failure reach the deploy command instead of reporting success', function () {
    $this->mock(LocaleManager::class)
        ->shouldReceive('supported')->andThrow(new RuntimeException('database unavailable'));

    expect(fn () => $this->artisan('rateguru:translations:backfill')->run())->toThrow(RuntimeException::class, 'database unavailable');
});
