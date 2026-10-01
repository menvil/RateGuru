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
use Illuminate\Support\Facades\Event;
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

it('never updates a translation it filled when a later release ships a different one', function () {
    // A repository change is not a command to change a project's content: once
    // the database has the text, the database owns it.
    backfill();
    $filled = presetValue('categories.0.name', $this->target);

    config(["project_presets.nature.categories.0.name.{$this->target}" => 'A better translation in a later release']);
    $report = backfill();

    expect(Category::query()->where('slug', 'landscape')->sole()->name_translations[$this->target])->toBe($filled)
        ->and($report->filled)->toBe(0);
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

it('leaves content an administrator created alone, without counting it', function () {
    // Content with no repository identity is not the backfill's at all: it is
    // neither filled nor reported. Skipped as unknown is for what the
    // repository expects and the database cannot match.
    $own = Category::factory()->create(['slug' => 'georgian-food', 'name' => 'Georgian food', 'name_translations' => null]);
    $ownTag = Tag::factory()->create(['slug' => 'blonde', 'name' => 'Blonde', 'name_translations' => null]);
    $ownGroup = RatingGroup::factory()->create(['key' => 'vintage', 'label' => 'Vintage', 'description' => null, 'label_translations' => null]);
    $ownOption = RatingOption::factory()->for($ownGroup, 'group')->create(['key' => 'retro', 'label' => 'Retro', 'label_translations' => null]);

    $report = backfill();

    expect($own->fresh()->name_translations)->toBeNull()
        ->and($ownTag->fresh()->name_translations)->toBeNull()
        ->and($ownGroup->fresh()->label_translations)->toBeNull()
        ->and($ownOption->fresh()->label_translations)->toBeNull()
        ->and($report->skippedUnknown)->toBe(0);
});

it('never reads content the repository has no identity for', function () {
    // However much an administrator has created, a deploy's backfill loads the
    // same rows: it looks up repository values, it does not walk the tables.
    backfill();
    $loaded = function (): int {
        $count = 0;
        Event::listen('eloquent.retrieved: *', function () use (&$count): void {
            $count++;
        });
        backfill();
        Event::forget('eloquent.retrieved: *');

        return $count;
    };
    $before = $loaded();

    foreach (range(1, 25) as $n) {
        Category::factory()->create(['slug' => "own-{$n}", 'name' => "Own {$n}", 'name_translations' => null]);
        Tag::factory()->create(['slug' => "own-tag-{$n}", 'name' => "Own tag {$n}", 'name_translations' => null]);
    }

    expect($before)->toBeGreaterThan(0)
        ->and($loaded())->toBe($before);
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

it('never updates static page text it filled when a later release ships a different one', function () {
    backfill();
    $filled = config("static-pages.defaults.about.{$this->target}.title");

    config(["static-pages.defaults.about.{$this->target}.title" => 'A better title in a later release']);
    $report = backfill();

    expect(ProjectSettings::findOrFail(1)->static_pages['about'][$this->target]['title'])->toBe($filled)
        ->and($report->filled)->toBe(0);
});

it('creates a built-in page a release adds, in every language the repository ships, once', function () {
    config(['static-pages.defaults.imprint' => [
        'en' => ['title' => 'Imprint', 'content' => 'Who runs this site.'],
        $this->target => ['title' => 'Imprint in another language', 'content' => 'Who runs this site, translated.'],
    ]]);

    backfill();
    $created = ProjectSettings::findOrFail(1)->static_pages['imprint'];

    expect($created['en'])->toBe(['title' => 'Imprint', 'content' => 'Who runs this site.'])
        ->and($created[$this->target])->toBe(['title' => 'Imprint in another language', 'content' => 'Who runs this site, translated.']);

    // From then on the page is the project's: a later release changes nothing.
    config(['static-pages.defaults.imprint.en.title' => 'Legal notice']);
    $after = storedTranslations();

    expect(backfill()->filled)->toBe(0)
        ->and(storedTranslations())->toBe($after);
});

it('creates every page for a row that has none', function () {
    ProjectSettings::query()->update(['static_pages' => null]);

    backfill();

    expect(ProjectSettings::findOrFail(1)->static_pages)->toBe(config('static-pages.defaults'));
});

it('fills a static page field the project left with no English at all, and then its translations', function () {
    // A row saved before the pages had content: blank English is a gap, not
    // the project's own text.
    $pages = ProjectSettings::findOrFail(1)->static_pages;
    $pages['privacy']['en']['content'] = '';
    unset($pages['privacy'][$this->target]['content']);
    ProjectSettings::query()->update(['static_pages' => json_encode($pages)]);

    backfill();
    $stored = ProjectSettings::findOrFail(1)->static_pages['privacy'];

    expect($stored['en']['content'])->toBe(config('static-pages.defaults.privacy.en.content'))
        ->and($stored[$this->target]['content'])->toBe(config("static-pages.defaults.privacy.{$this->target}.content"));
});

it('fills a language the project does not offer yet', function () {
    offerEveryInstalledLocaleExcept($this->target);

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
