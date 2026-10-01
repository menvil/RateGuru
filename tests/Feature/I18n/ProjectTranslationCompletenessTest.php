<?php

use App\Models\Category;
use App\Models\ProjectSettings;
use App\Models\RatingGroup;
use App\Models\RatingOption;
use App\Models\Tag;
use App\Support\Settings\PresetSettingsBuilder;
use App\Support\Translations\MissingProjectTranslation;
use App\Support\Translations\MissingTranslationReason;
use App\Support\Translations\ProjectContentSection;
use App\Support\Translations\ProjectTranslationCompleteness;
use App\Support\Translations\ProjectTranslationReport;

/**
 * Project content completeness reads the database as it is: what an
 * administrator created counts, what no visitor sees does not, and a blank
 * reference text needs nothing.
 */
function projectCompleteness(string $locale): ProjectTranslationReport
{
    return app(ProjectTranslationCompleteness::class)->report($locale);
}

/** @return list<string> "key.field" of what a language is missing in one section */
function missingIn(ProjectTranslationReport $report, ProjectContentSection $section): array
{
    return array_values(array_map(
        fn (MissingProjectTranslation $item): string => "{$item->key}.{$item->field}",
        array_filter($report->missing, fn (MissingProjectTranslation $item): bool => $item->section === $section),
    ));
}

/** @return array<string, MissingTranslationReason> why each "key.field" a language is missing in one section is missing */
function missingReasonsIn(ProjectTranslationReport $report, ProjectContentSection $section): array
{
    return collect($report->missing)
        ->filter(fn (MissingProjectTranslation $item): bool => $item->section === $section)
        ->mapWithKeys(fn (MissingProjectTranslation $item): array => ["{$item->key}.{$item->field}" => $item->reason])
        ->all();
}

/** Project settings with every translatable field translated into these languages. */
function translatedProjectSettings(array $locales, array $overrides = []): ProjectSettings
{
    return ProjectSettings::factory()->create([...projectSettingsTranslationsIn($locales), 'site_description' => 'About this site', ...$overrides]);
}

// Project settings ------------------------------------------------------------

it('counts a project setting as translated when the language has text for it', function () {
    [$target] = twoTranslatedLocales();
    translatedProjectSettings([$target]);

    expect(missingIn(projectCompleteness($target), ProjectContentSection::ProjectSettings))->toBe([]);
});

it('counts a project setting as missing when its translation is not text', function (mixed $value) {
    [$target] = twoTranslatedLocales();
    translatedProjectSettings([$target], ['site_name_translations' => [$target => $value]]);

    expect(missingReasonsIn(projectCompleteness($target), ProjectContentSection::ProjectSettings))
        ->toBe(['site_name.site_name' => MissingTranslationReason::Untranslated]);
})->with('not a translation');

it('asks no translation of a project setting whose reference text is blank', function () {
    [$target] = twoTranslatedLocales();
    translatedProjectSettings([$target], ['site_description' => null, 'site_description_translations' => null, 'site_tagline' => '  ', 'site_tagline_translations' => null]);

    expect(missingIn(projectCompleteness($target), ProjectContentSection::ProjectSettings))->toBe([]);
});

it('takes the reference language from the base columns alone', function () {
    ProjectSettings::factory()->create(['site_name_translations' => null]);

    expect(missingIn(projectCompleteness('en'), ProjectContentSection::ProjectSettings))->toBe([]);
});

// Static pages -----------------------------------------------------------------

it('counts an untouched static page as translated through its configured text', function () {
    [$target] = twoTranslatedLocales();
    ProjectSettings::factory()->create(['static_pages' => null]);

    expect(missingIn(projectCompleteness($target), ProjectContentSection::StaticPages))->toBe([]);
});

it('counts a stored copy of an unchanged page as translated', function () {
    // What the Project Settings form stores for every language on save.
    [$target] = twoTranslatedLocales();
    ProjectSettings::factory()->create(['static_pages' => config('static-pages.defaults')]);

    expect(missingIn(projectCompleteness($target), ProjectContentSection::StaticPages))->toBe([]);
});

it('asks for a new translation of a page whose English was rewritten', function () {
    [$target] = twoTranslatedLocales();
    $pages = config('static-pages.defaults');
    $pages['about']['en']['title'] = 'About TitsGuru';
    unset($pages['about'][$target]);
    ProjectSettings::factory()->create(['static_pages' => $pages]);

    expect(missingReasonsIn(projectCompleteness($target), ProjectContentSection::StaticPages))
        ->toBe(['about.title' => MissingTranslationReason::SourceCustomized]);
});

it('does not take the configured translation of the old text for the rewritten one', function () {
    [$target] = twoTranslatedLocales();
    $pages = config('static-pages.defaults');
    $pages['about']['en']['title'] = 'About TitsGuru';
    ProjectSettings::factory()->create(['static_pages' => $pages]);

    expect($pages['about'][$target]['title'])->toBe(config("static-pages.defaults.about.{$target}.title"))
        ->and(missingReasonsIn(projectCompleteness($target), ProjectContentSection::StaticPages))
        ->toBe(['about.title' => MissingTranslationReason::StaleRepositoryDefault]);
});

it('calls a rewritten page plainly untranslated where the repository never translated it', function () {
    // No shipped translation of the old English, so there is nothing stale
    // and nothing customized away — the language simply has no text.
    [$target] = twoTranslatedLocales();
    config(['static-pages.defaults.imprint' => ['en' => ['title' => 'Imprint', 'content' => 'Who runs this site.']]]);
    ProjectSettings::factory()->create(['static_pages' => ['imprint' => ['en' => ['title' => 'Legal notice', 'content' => 'Who runs this site.']]]]);

    expect(missingReasonsIn(projectCompleteness($target), ProjectContentSection::StaticPages))->toBe([
        'imprint.title' => MissingTranslationReason::Untranslated,
        'imprint.content' => MissingTranslationReason::Untranslated,
    ]);
});

it('counts a rewritten page once the language has its own text for it', function () {
    [$target] = twoTranslatedLocales();
    $pages = config('static-pages.defaults');
    $pages['about']['en']['title'] = 'About TitsGuru';
    $pages['about'][$target]['title'] = 'A translation of the new title';
    ProjectSettings::factory()->create(['static_pages' => $pages]);

    expect(missingIn(projectCompleteness($target), ProjectContentSection::StaticPages))->toBe([]);
});

it('reads every static page config declares', function () {
    [$target] = twoTranslatedLocales();
    config(['static-pages.defaults.imprint' => ['en' => ['title' => 'Imprint', 'content' => 'Who runs this site.']]]);
    ProjectSettings::factory()->create(['static_pages' => null]);

    expect(missingReasonsIn(projectCompleteness($target), ProjectContentSection::StaticPages))->toBe([
        'imprint.title' => MissingTranslationReason::Untranslated,
        'imprint.content' => MissingTranslationReason::Untranslated,
    ]);
});

// Content -----------------------------------------------------------------------

it('counts an active category, including one an administrator created', function () {
    [$target] = twoTranslatedLocales();
    Category::factory()->create(['slug' => 'georgian-food', 'name' => 'Georgian food', 'name_translations' => null, 'is_active' => true]);
    Category::factory()->create(['slug' => 'translated', 'name' => 'Translated', 'name_translations' => [$target => 'Text'], 'is_active' => true]);

    expect(missingIn(projectCompleteness($target), ProjectContentSection::Categories))->toBe(['georgian-food.name']);
});

it('counts a category whose translation is not text as missing', function (mixed $value) {
    [$target] = twoTranslatedLocales();
    Category::factory()->create(['slug' => 'pasta', 'name' => 'Pasta', 'name_translations' => [$target => $value], 'is_active' => true]);

    expect(missingIn(projectCompleteness($target), ProjectContentSection::Categories))->toBe(['pasta.name']);
})->with('not a translation');

it('leaves inactive categories out', function () {
    [$target] = twoTranslatedLocales();
    Category::factory()->create(['slug' => 'retired', 'name' => 'Retired', 'name_translations' => null, 'is_active' => false]);

    expect(missingIn(projectCompleteness($target), ProjectContentSection::Categories))->toBe([]);
});

it('counts a rating group label and description, but not a blank description', function () {
    [$target] = twoTranslatedLocales();
    RatingGroup::factory()->create(['key' => 'with_description', 'label' => 'Label', 'description' => 'Description', 'label_translations' => [$target => 'L'], 'description_translations' => null, 'is_active' => true]);
    RatingGroup::factory()->create(['key' => 'no_description', 'label' => 'Label', 'description' => null, 'label_translations' => null, 'is_active' => true]);
    RatingGroup::factory()->create(['key' => 'inactive', 'label' => 'Label', 'description' => 'D', 'is_active' => false]);

    expect(missingIn(projectCompleteness($target), ProjectContentSection::RatingGroups))
        ->toBe(['with_description.description', 'no_description.label']);
});

it('counts active options of active groups only', function () {
    [$target] = twoTranslatedLocales();
    $group = RatingGroup::factory()->create(['key' => 'size', 'label' => 'Size', 'label_translations' => [$target => 'S'], 'is_active' => true]);
    $inactiveGroup = RatingGroup::factory()->create(['key' => 'retired', 'label' => 'Retired', 'is_active' => false]);

    RatingOption::factory()->for($group, 'group')->create(['key' => 'large', 'label' => 'Large', 'description' => null, 'label_translations' => null, 'is_active' => true]);
    RatingOption::factory()->for($group, 'group')->create(['key' => 'inactive', 'label' => 'Inactive', 'is_active' => false]);
    RatingOption::factory()->for($group, 'group')->create(['key' => 'archived', 'label' => 'Archived', 'is_active' => true, 'archived_at' => now()]);
    RatingOption::factory()->for($inactiveGroup, 'group')->create(['key' => 'orphaned', 'label' => 'Orphaned', 'is_active' => true]);

    expect(missingIn(projectCompleteness($target), ProjectContentSection::RatingOptions))->toBe(['size.large.label']);
});

it('counts every tag', function () {
    [$target] = twoTranslatedLocales();
    Tag::factory()->create(['slug' => 'blonde', 'name' => 'Blonde', 'name_translations' => null]);
    Tag::factory()->create(['slug' => 'hd', 'name' => 'HD', 'name_translations' => [$target => 'HD']]);

    expect(missingIn(projectCompleteness($target), ProjectContentSection::Tags))->toBe(['blonde.name']);
});

// The report --------------------------------------------------------------------

it('counts the fields that need a translation and the share that has one', function () {
    [$target] = twoTranslatedLocales();
    translatedProjectSettings([$target], ['static_pages' => null]);
    Category::factory()->create(['slug' => 'a', 'name' => 'A', 'name_translations' => [$target => 'A'], 'is_active' => true]);
    Category::factory()->create(['slug' => 'b', 'name' => 'B', 'name_translations' => null, 'is_active' => true]);
    Tag::factory()->create(['slug' => 'c', 'name' => 'C', 'name_translations' => null]);

    $report = projectCompleteness($target);
    $settingsFields = count(PresetSettingsBuilder::TRANSLATABLE);
    $pageFields = count(config('static-pages.defaults')) * 2;
    $required = $settingsFields + $pageFields + 3;

    expect($report->required)->toBe($required)
        ->and($report->translated)->toBe($required - 2)
        ->and(count($report->missing))->toBe(2)
        ->and($report->percentage())->toBe(intdiv(($required - 2) * 100, $required))
        ->and($report->isComplete())->toBeFalse()
        ->and(array_keys($report->missingBySection()))->toBe([ProjectContentSection::Categories->value, ProjectContentSection::Tags->value]);
});

it('reports a project with nothing to translate as complete', function () {
    [$target] = twoTranslatedLocales();
    config(['static-pages.defaults' => []]);
    ProjectSettings::factory()->create(collect(PresetSettingsBuilder::TRANSLATABLE)->mapWithKeys(fn (string $field): array => [$field => ''])->all());

    $report = projectCompleteness($target);

    expect($report->required)->toBe(0)
        ->and($report->percentage())->toBe(100)
        ->and($report->isComplete())->toBeTrue();
});

it('describes a missing item well enough to find and fix it', function () {
    [$target] = twoTranslatedLocales();
    $group = RatingGroup::factory()->create(['key' => 'cup_size', 'label' => 'Cup size', 'label_translations' => [$target => 'x'], 'is_active' => true]);
    $option = RatingOption::factory()->for($group, 'group')->create(['key' => 'dd', 'label' => 'DD', 'description' => null, 'label_translations' => null, 'is_active' => true]);

    $item = collect(projectCompleteness($target)->missing)->firstWhere('section', ProjectContentSection::RatingOptions);

    expect($item)->toEqual(new MissingProjectTranslation(ProjectContentSection::RatingOptions, $option->id, $group->id, 'cup_size.dd', 'Cup size → DD', 'label'));
});
