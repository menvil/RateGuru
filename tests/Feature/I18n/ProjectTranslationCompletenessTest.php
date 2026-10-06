<?php

use App\Models\Category;
use App\Models\ProjectSettings;
use App\Models\RatingGroup;
use App\Models\RatingOption;
use App\Models\Tag;
use App\Support\Settings\PresetSettingsBuilder;
use App\Support\Translations\ProjectContentSection;
use App\Support\Translations\ProjectTranslationCompleteness;
use App\Support\Translations\ProjectTranslationReport;
use App\Support\Translations\ProjectTranslationUnit;

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
        fn (ProjectTranslationUnit $item): string => "{$item->key}.{$item->field}",
        array_filter($report->missing, fn (ProjectTranslationUnit $item): bool => $item->section === $section),
    ));
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

    expect(missingIn(projectCompleteness($target), ProjectContentSection::ProjectSettings))->toBe(['site_name.site_name']);
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

it('counts a static page language as translated when the project stores text for it', function () {
    [$target] = twoTranslatedLocales();
    ProjectSettings::factory()->create(['static_pages' => config('static-pages.defaults')]);

    expect(missingIn(projectCompleteness($target), ProjectContentSection::StaticPages))->toBe([]);
});

it('counts a static page language the project stores no text for as missing, whatever the repository ships', function () {
    // config/static-pages.php has this language; the project does not, and
    // the project's text is all a visitor is shown.
    [$target] = twoTranslatedLocales();
    $pages = config('static-pages.defaults');
    unset($pages['about'][$target]);
    $pages['privacy'][$target]['title'] = '   ';
    ProjectSettings::factory()->create(['static_pages' => $pages]);

    expect(config("static-pages.defaults.about.{$target}"))->not->toBeNull()
        ->and(missingIn(projectCompleteness($target), ProjectContentSection::StaticPages))
        ->toBe(['about.title', 'about.content', 'privacy.title']);
});

it('takes a stored translation as it is, whatever the English beside it says', function () {
    // The project owns both texts; nothing compares them with the repository.
    [$target] = twoTranslatedLocales();
    $pages = config('static-pages.defaults');
    $pages['about']['en']['title'] = 'About us, rewritten';
    ProjectSettings::factory()->create(['static_pages' => $pages]);

    expect(missingIn(projectCompleteness($target), ProjectContentSection::StaticPages))->toBe([]);
});

it('asks no translation of a static page field whose English the project leaves blank', function () {
    [$target] = twoTranslatedLocales();
    $pages = config('static-pages.defaults');
    $pages['about']['en']['content'] = '';
    unset($pages['about'][$target]);
    ProjectSettings::factory()->create(['static_pages' => $pages]);

    expect(missingIn(projectCompleteness($target), ProjectContentSection::StaticPages))->toBe(['about.title']);
});

it('reads every built-in page, from the project only', function () {
    // A page config adds is the project's only once the backfill has created
    // it there; until then the project has no text for it.
    [$target] = twoTranslatedLocales();
    config(['static-pages.defaults.imprint' => ['en' => ['title' => 'Imprint', 'content' => 'Who runs this site.'], $target => ['title' => 'x', 'content' => 'y']]]);
    ProjectSettings::factory()->create(['static_pages' => config('static-pages.defaults')]);
    $pages = ProjectSettings::findOrFail(1)->static_pages;
    $pages['imprint'][$target] = [];
    ProjectSettings::query()->update(['static_pages' => json_encode($pages)]);

    expect(missingIn(projectCompleteness($target), ProjectContentSection::StaticPages))->toBe(['imprint.title', 'imprint.content']);
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
    translatedProjectSettings([$target], ['static_pages' => config('static-pages.defaults')]);
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

    expect($item)->toBeInstanceOf(ProjectTranslationUnit::class)
        ->and([$item->section, $item->recordId, $item->parentId, $item->key, $item->label, $item->field, $item->reference])
        ->toBe([ProjectContentSection::RatingOptions, $option->id, $group->id, 'cup_size.dd', 'Cup size → DD', 'label', 'DD'])
        ->and($item->id)->toBe("rating_options:{$option->id}:label");
});

// One project, every rule at once ------------------------------------------------

/**
 * A project that exercises every rule together: partly translated settings,
 * a blank English field, a static page with a language and a field missing,
 * active and inactive content, archived options and an inactive group's
 * options, and tags with and without a translation.
 */
function everyRuleProject(string $target, string $other): void
{
    $pages = config('static-pages.defaults');
    unset($pages['about'][$target]);
    $pages['privacy'][$target]['content'] = '  ';
    $pages['terms']['en']['content'] = '';
    unset($pages['contact'][$other]);

    ProjectSettings::factory()->create([
        ...projectSettingsTranslationsIn([$other]),
        'site_name_translations' => [$target => 'Name', $other => ''],
        'site_tagline_translations' => [$target => '   '],
        'site_description' => '',
        'feed_title_translations' => ['en' => 'Latest posts', $target => 'Feed'],
        'static_pages' => $pages,
    ]);

    Category::factory()->create(['slug' => 'first', 'name' => 'First', 'name_translations' => [$target => 'Erste'], 'is_active' => true]);
    Category::factory()->create(['slug' => 'second', 'name' => 'Second', 'name_translations' => null, 'is_active' => true]);
    Category::factory()->create(['slug' => 'retired', 'name' => 'Retired', 'name_translations' => null, 'is_active' => false]);

    $size = RatingGroup::factory()->create(['key' => 'size', 'label' => 'Size', 'description' => 'How big', 'label_translations' => [$other => 'Größe'], 'description_translations' => [$target => 'Wie groß'], 'is_active' => true]);
    RatingGroup::factory()->create(['key' => 'plain', 'label' => 'Plain', 'description' => null, 'label_translations' => null, 'is_active' => true]);
    $retired = RatingGroup::factory()->create(['key' => 'retired', 'label' => 'Retired', 'description' => 'Gone', 'is_active' => false]);

    RatingOption::factory()->for($size, 'group')->create(['key' => 'large', 'label' => 'Large', 'description' => 'Very big', 'label_translations' => [$target => 'Groß'], 'description_translations' => null, 'is_active' => true]);
    RatingOption::factory()->for($size, 'group')->create(['key' => 'small', 'label' => 'Small', 'description' => null, 'label_translations' => null, 'is_active' => true]);
    RatingOption::factory()->for($size, 'group')->create(['key' => 'hidden', 'label' => 'Hidden', 'is_active' => false]);
    RatingOption::factory()->for($size, 'group')->create(['key' => 'archived', 'label' => 'Archived', 'is_active' => true, 'archived_at' => now()]);
    RatingOption::factory()->for($retired, 'group')->create(['key' => 'orphan', 'label' => 'Orphan', 'is_active' => true]);

    Tag::factory()->create(['slug' => 'blonde', 'name' => 'Blonde', 'name_translations' => [$other => 'Blond']]);
    Tag::factory()->create(['slug' => 'hd', 'name' => 'HD', 'name_translations' => [$target => 'HD', $other => 'HD']]);
}

/** @return list<string> every missing item as section:key.field (label) “reference” */
function describedMissing(ProjectTranslationReport $report): array
{
    return array_map(
        fn (ProjectTranslationUnit $item): string => "{$item->section->value}:{$item->key}.{$item->field} ({$item->label}) “{$item->reference}”",
        $report->missing,
    );
}

it('reports a project that exercises every rule exactly as it always has', function () {
    [$target, $other] = twoTranslatedLocales();
    everyRuleProject($target, $other);

    $reports = app(ProjectTranslationCompleteness::class)->reports(['en', $target, $other]);
    $about = config('static-pages.defaults.about.en');
    $privacy = config('static-pages.defaults.privacy.en');
    $contact = config('static-pages.defaults.contact.en');

    // Seven settings but the blank description, four pages of two fields but
    // the blank terms content, two active categories, two active groups'
    // labels and one description, two live options' labels and one
    // description, two tags.
    expect($reports['en']->required)->toBe(23)
        ->and($reports['en']->translated)->toBe(23)
        ->and($reports['en']->missing)->toBe([])
        ->and($reports[$target]->required)->toBe(23)
        ->and($reports[$target]->translated)->toBe(10)
        ->and(describedMissing($reports[$target]))->toBe([
            'project_settings:site_tagline.site_tagline (Site Tagline) “Rate anything”',
            'project_settings:object_singular_name.object_singular_name (Object Singular Name) “post”',
            'project_settings:object_plural_name.object_plural_name (Object Plural Name) “posts”',
            'project_settings:upload_cta_label.upload_cta_label (Upload Cta Label) “Upload post”',
            "static_pages:about.title (About) “{$about['title']}”",
            "static_pages:about.content (About) “{$about['content']}”",
            "static_pages:privacy.content (Privacy) “{$privacy['content']}”",
            'categories:second.name (Second) “Second”',
            'rating_groups:size.label (Size) “Size”',
            'rating_groups:plain.label (Plain) “Plain”',
            'rating_options:size.large.description (Size → Large) “Very big”',
            'rating_options:size.small.label (Size → Small) “Small”',
            'tags:blonde.name (Blonde) “Blonde”',
        ])
        ->and($reports[$other]->required)->toBe(23)
        ->and($reports[$other]->translated)->toBe(11)
        ->and(describedMissing($reports[$other]))->toBe([
            'project_settings:site_name.site_name (Site Name) “RateGuru”',
            'project_settings:site_tagline.site_tagline (Site Tagline) “Rate anything”',
            'project_settings:feed_title.feed_title (Feed Title) “Latest posts”',
            "static_pages:contact.title (Contact) “{$contact['title']}”",
            "static_pages:contact.content (Contact) “{$contact['content']}”",
            'categories:first.name (First) “First”',
            'categories:second.name (Second) “Second”',
            'rating_groups:size.description (Size) “How big”',
            'rating_groups:plain.label (Plain) “Plain”',
            'rating_options:size.large.label (Size → Large) “Large”',
            'rating_options:size.large.description (Size → Large) “Very big”',
            'rating_options:size.small.label (Size → Small) “Small”',
        ]);
});
