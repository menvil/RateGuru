<?php

use App\Filament\Pages\ProjectSettingsPage;
use App\Filament\Resources\Categories\Schemas\CategoryForm;
use App\Filament\Resources\RatingGroups\Pages\EditRatingGroup;
use App\Filament\Resources\RatingGroups\RelationManagers\OptionsRelationManager;
use App\Filament\Resources\RatingGroups\Schemas\RatingGroupForm;
use App\Filament\Resources\Tags\Schemas\TagForm;
use App\Models\Category;
use App\Models\ProjectSettings;
use App\Models\RatingGroup;
use App\Models\RatingOption;
use App\Models\Tag;
use App\Models\User;
use App\Support\Settings\PresetSettingsBuilder;
use App\Support\Translations\ProjectContentSection;
use App\Support\Translations\ProjectTranslationCatalog;
use App\Support\Translations\ProjectTranslationUnit;
use App\Support\Translations\RepositoryTranslations;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * The catalog is the one list of the project's translatable content: what
 * Languages counts and Translation Center edits. Each unit is read from the
 * database as it is now, carries the limits its editor enforces, and is known
 * by an id that survives renaming.
 */
function translationCatalog(): ProjectTranslationCatalog
{
    return app(ProjectTranslationCatalog::class);
}

/** @return array<string, ProjectTranslationUnit> every unit, by id */
function catalogUnits(): array
{
    return collect(translationCatalog()->units())->keyBy('id')->all();
}

/** @return array<string, ProjectTranslationUnit> one section's units, by id */
function catalogUnitsOf(ProjectContentSection $section): array
{
    return array_filter(catalogUnits(), fn (ProjectTranslationUnit $unit): bool => $unit->section === $section);
}

/** @return list<mixed> what an editor enforces on a unit: [max length, multiline] */
function unitLimits(ProjectTranslationUnit $unit): array
{
    return [$unit->maxLength, $unit->multiline];
}

// What is listed ----------------------------------------------------------------

it('lists every translatable project setting, with the limits of its editor', function () {
    [$target] = twoTranslatedLocales();
    ProjectSettings::factory()->create([...projectSettingsTranslationsIn([$target]), 'site_description' => 'About this site']);

    $units = catalogUnitsOf(ProjectContentSection::ProjectSettings);

    expect(array_keys($units))->toBe(array_map(fn (string $field): string => "project_settings:{$field}", PresetSettingsBuilder::TRANSLATABLE))
        ->and(array_map(unitLimits(...), $units))->toBe([
            'project_settings:site_name' => [120, false],
            'project_settings:site_tagline' => [180, false],
            'project_settings:site_description' => [2000, true],
            'project_settings:object_singular_name' => [80, false],
            'project_settings:object_plural_name' => [80, false],
            'project_settings:upload_cta_label' => [80, false],
            'project_settings:feed_title' => [120, false],
        ]);

    $tagline = $units['project_settings:site_tagline'];

    expect([$tagline->recordId, $tagline->parentId, $tagline->key, $tagline->label, $tagline->field, $tagline->reference])
        ->toBe([null, null, 'site_tagline', 'Site Tagline', 'site_tagline', 'Rate anything'])
        ->and($tagline->translation($target))->toBe("site_tagline in {$target}")
        ->and($tagline->fieldLabel())->toBeNull()
        ->and($tagline->qualifiedKey())->toBe('project_settings.site_tagline');
});

it('lists the title and content of every built-in static page, as the project stores them', function () {
    [$target] = twoTranslatedLocales();
    ProjectSettings::factory()->create(['static_pages' => staticPagesTranslatedInto([$target])]);

    $units = catalogUnitsOf(ProjectContentSection::StaticPages);
    $expected = collect(array_keys(config('static-pages.defaults')))
        ->flatMap(fn (string $page): array => ["static_pages:{$page}:title", "static_pages:{$page}:content"])
        ->all();

    expect(array_keys($units))->toBe($expected)
        ->and(unitLimits($units['static_pages:about:title']))->toBe([160, false])
        ->and(unitLimits($units['static_pages:about:content']))->toBe([20000, true])
        ->and($units['static_pages:about:title']->reference)->toBe(config('static-pages.defaults.about.en.title'))
        ->and($units['static_pages:about:title']->translation($target))->toBe("[{$target}] ".config('static-pages.defaults.about.en.title'))
        ->and([$units['static_pages:about:content']->key, $units['static_pages:about:content']->label, $units['static_pages:about:content']->fieldLabel()])
        ->toBe(['about', 'About', 'Content']);
});

it('takes static page text from the project only, never from the repository', function () {
    [$target] = twoTranslatedLocales();
    shipRepositoryContentTranslatedInto([$target]);
    $pages = config('static-pages.defaults');
    unset($pages['about'][$target]);
    $pages['about']['en']['title'] = 'About us, rewritten';
    ProjectSettings::factory()->create(['static_pages' => $pages]);

    $unit = catalogUnits()['static_pages:about:title'];

    expect(config("static-pages.defaults.about.{$target}.title"))->not->toBeNull()
        ->and($unit->reference)->toBe('About us, rewritten')
        ->and($unit->translation($target))->toBeNull();
});

it('lists an active category by its record id, with its slug as the business key', function () {
    [$target] = twoTranslatedLocales();
    ProjectSettings::factory()->create();
    $category = Category::factory()->create(['slug' => 'small-pets', 'name' => 'Rabbits & rodents', 'name_translations' => [$target => 'Кролики'], 'is_active' => true]);

    $unit = catalogUnits()["categories:{$category->id}:name"];

    expect([$unit->section, $unit->recordId, $unit->parentId, $unit->key, $unit->label, $unit->field, $unit->reference])
        ->toBe([ProjectContentSection::Categories, $category->id, null, 'small-pets', 'Rabbits & rodents', 'name', 'Rabbits & rodents'])
        ->and(unitLimits($unit))->toBe([80, false])
        ->and($unit->translation($target))->toBe('Кролики')
        ->and($unit->qualifiedKey())->toBe('categories.small-pets.name');
});

it('lists every tag', function () {
    ProjectSettings::factory()->create();
    $tag = Tag::factory()->create(['slug' => 'zoomies', 'name' => 'zoomies', 'name_translations' => null]);

    $unit = catalogUnits()["tags:{$tag->id}:name"];

    expect([$unit->section, $unit->recordId, $unit->key, $unit->label, $unit->field])
        ->toBe([ProjectContentSection::Tags, $tag->id, 'zoomies', 'zoomies', 'name'])
        ->and(unitLimits($unit))->toBe([80, false])
        ->and($unit->translations)->toBe([]);
});

it('lists the label and description of an active rating group', function () {
    [$target] = twoTranslatedLocales();
    ProjectSettings::factory()->create();
    $group = RatingGroup::factory()->create(['key' => 'vibe', 'label' => 'Vibe', 'description' => 'What is this pet like?', 'label_translations' => [$target => 'Характер'], 'is_active' => true]);

    $units = catalogUnits();
    $label = $units["rating_groups:{$group->id}:label"];
    $description = $units["rating_groups:{$group->id}:description"];

    expect([$label->key, $label->label, $label->field, $label->reference, $label->translation($target)])->toBe(['vibe', 'Vibe', 'label', 'Vibe', 'Характер'])
        ->and(unitLimits($label))->toBe([120, false])
        ->and([$description->field, $description->reference, $description->translation($target)])->toBe(['description', 'What is this pet like?', null])
        ->and(unitLimits($description))->toBe([1000, true]);
});

it('lists the label and description of a live rating option, under its group', function () {
    ProjectSettings::factory()->create();
    $group = RatingGroup::factory()->create(['key' => 'vibe', 'label' => 'Vibe', 'is_active' => true]);
    $option = RatingOption::factory()->for($group, 'group')->create(['key' => 'chaos_gremlin', 'label' => 'Chaos gremlin', 'description' => 'Knocks things over', 'is_active' => true]);

    $units = catalogUnits();
    $label = $units["rating_options:{$option->id}:label"];

    expect([$label->recordId, $label->parentId, $label->key, $label->label, $label->field])
        ->toBe([$option->id, $group->id, 'vibe.chaos_gremlin', 'Vibe → Chaos gremlin', 'label'])
        ->and(unitLimits($label))->toBe([120, false])
        ->and(unitLimits($units["rating_options:{$option->id}:description"]))->toBe([1000, true])
        ->and($label->qualifiedKey())->toBe('rating_options.vibe.chaos_gremlin.label');
});

it('leaves out what no visitor sees', function () {
    ProjectSettings::factory()->create();
    $category = Category::factory()->create(['is_active' => false]);
    $group = RatingGroup::factory()->create(['is_active' => true]);
    $inactiveGroup = RatingGroup::factory()->create(['is_active' => false]);
    $inactive = RatingOption::factory()->for($group, 'group')->create(['is_active' => false]);
    $archived = RatingOption::factory()->for($group, 'group')->create(['is_active' => true, 'archived_at' => now()]);
    $orphan = RatingOption::factory()->for($inactiveGroup, 'group')->create(['is_active' => true]);

    $ids = array_keys(catalogUnits());

    expect($ids)->not->toContain("categories:{$category->id}:name")
        ->not->toContain("rating_groups:{$inactiveGroup->id}:label")
        ->not->toContain("rating_options:{$inactive->id}:label")
        ->not->toContain("rating_options:{$archived->id}:label")
        ->not->toContain("rating_options:{$orphan->id}:label")
        ->toContain("rating_groups:{$group->id}:label");
});

it('needs no translation where the English text is blank', function (mixed $blank) {
    ProjectSettings::factory()->create(['site_description' => $blank]);
    $group = RatingGroup::factory()->create(['description' => $blank, 'is_active' => true]);

    $units = catalogUnits();

    expect($units['project_settings:site_description']->requiresTranslation())->toBeFalse()
        ->and($units["rating_groups:{$group->id}:description"]->requiresTranslation())->toBeFalse()
        ->and($units["rating_groups:{$group->id}:label"]->requiresTranslation())->toBeTrue();
})->with(['null' => [null], 'empty' => [''], 'spaces' => ['   ']]);

it('reads a stored translation as one, and anything else as missing', function (mixed $value) {
    [$target, $other] = twoTranslatedLocales();
    ProjectSettings::factory()->create();
    $category = Category::factory()->create(['name_translations' => [$target => $value, $other => 'Text'], 'is_active' => true]);

    $unit = catalogUnits()["categories:{$category->id}:name"];

    expect($unit->translation($target))->toBeNull()
        ->and($unit->isTranslatedInto($target))->toBeFalse()
        ->and($unit->translation($other))->toBe('Text')
        ->and($unit->isTranslatedInto($other))->toBeTrue()
        // English is the base column, translated by definition.
        ->and($unit->isTranslatedInto('en'))->toBeTrue();
})->with('not a translation');

it('finds the placeholders of the English text, each once and in order', function () {
    ProjectSettings::factory()->create();
    $category = Category::factory()->create(['name' => 'Write to {contact_email} about {site_name} via {contact_email} {not one}', 'is_active' => true]);

    expect(catalogUnits()["categories:{$category->id}:name"]->placeholders())->toBe(['{contact_email}', '{site_name}']);
});

// Identity -----------------------------------------------------------------------

it('keeps a unit\'s id when its name, slug or key is changed', function () {
    ProjectSettings::factory()->create();
    $category = Category::factory()->create(['slug' => 'dogs', 'name' => 'Dogs', 'is_active' => true]);
    $group = RatingGroup::factory()->create(['key' => 'size', 'label' => 'Size', 'is_active' => true]);
    $option = RatingOption::factory()->for($group, 'group')->create(['key' => 'large', 'label' => 'Large', 'is_active' => true]);

    $before = array_keys(catalogUnits());

    $category->update(['slug' => 'canines', 'name' => 'Canines']);
    $group->update(['key' => 'dimensions', 'label' => 'Dimensions']);
    $option->update(['key' => 'big', 'label' => 'Big']);

    $after = catalogUnits();

    expect(array_keys($after))->toBe($before)
        ->and($after["categories:{$category->id}:name"]->key)->toBe('canines')
        ->and($after["rating_options:{$option->id}:label"]->label)->toBe('Dimensions → Big');
});

it('finds one unit by its id, the same as the list has it, reading only its own row', function () {
    [$target] = twoTranslatedLocales();
    ProjectSettings::factory()->create();
    Category::factory()->count(3)->create(['is_active' => true]);
    $group = RatingGroup::factory()->create(['is_active' => true]);
    $option = RatingOption::factory()->for($group, 'group')->create(['label_translations' => [$target => 'X'], 'is_active' => true]);
    $units = catalogUnits();

    DB::enableQueryLog();
    $found = translationCatalog()->find("rating_options:{$option->id}:label");
    $queries = collect(DB::getQueryLog())->pluck('query');

    expect($found)->toEqual($units["rating_options:{$option->id}:label"])
        ->and($queries->filter(fn (string $query): bool => preg_match('/from\s+["`]?categories["`]?(\s|$)/i', $query) === 1))->toBeEmpty()
        ->and(translationCatalog()->find('project_settings:site_name'))->toEqual($units['project_settings:site_name'])
        ->and(translationCatalog()->find('static_pages:contact:content'))->toEqual($units['static_pages:contact:content']);
});

it('finds nothing for an id that names nothing the catalog lists', function (Closure $id) {
    ProjectSettings::factory()->create();
    Category::factory()->create(['is_active' => true]);

    expect(translationCatalog()->find($id()))->toBeNull();
})->with([
    'not a string' => [fn (): mixed => 42],
    'an array' => [fn (): mixed => ['categories', 1, 'name']],
    'empty' => [fn (): string => ''],
    'a section alone' => [fn (): string => 'categories'],
    'an unknown section' => [fn (): string => 'posts:1:title'],
    'a field the section does not translate' => [fn (): string => 'categories:'.Category::query()->value('id').':slug'],
    'a record id that is not a number' => [fn (): string => 'categories:abc:name'],
    'a record id written with a leading zero' => [fn (): string => 'categories:0'.Category::query()->value('id').':name'],
    'a record id of zero' => [fn (): string => 'categories:0:name'],
    'a deleted record' => [fn (): string => 'categories:'.(Category::query()->max('id') + 1).':name'],
    'an inactive record' => [fn (): string => 'categories:'.Category::factory()->create(['is_active' => false])->id.':name'],
    'one part too many' => [fn (): string => 'categories:'.Category::query()->value('id').':name:extra'],
    'a static page that is not built in' => [fn (): string => 'static_pages:imprint:title'],
    'a setting that is not translated' => [fn (): string => 'project_settings:enabled_locales'],
    'a setting with a record id' => [fn (): string => 'project_settings:1:site_name'],
]);

// Limits -------------------------------------------------------------------------

/**
 * The translation fields of a form, by state path within the form: the
 * maximum length each enforces and whether it is a textarea.
 *
 * @return array<string, array{0: ?int, 1: bool}>
 */
function translationFieldsOf(Schema $form): array
{
    return collect($form->getFlatFields(withHidden: true))
        ->filter(fn (Field $field, string $key): bool => str_contains($key, '_translations.') || str_starts_with($key, 'static_pages.'))
        ->map(fn (Field $field): array => [$field->getMaxLength(), $field instanceof Textarea])
        ->all();
}

it('holds every unit to the limits the editors that still translate in place enforce', function () {
    ProjectSettings::factory()->create();
    $this->actingAs(User::factory()->admin()->create());
    [$target] = twoTranslatedLocales();
    $fields = ProjectTranslationCatalog::FIELDS;
    $group = RatingGroup::factory()->create();
    $settingsPage = Livewire::test(ProjectSettingsPage::class)->instance();
    $groupPage = Livewire::test(EditRatingGroup::class, ['record' => $group->id])->instance();
    $optionsManager = Livewire::test(OptionsRelationManager::class, ['ownerRecord' => $group, 'pageClass' => EditRatingGroup::class])->instance();

    $settings = translationFieldsOf($settingsPage->form(Schema::make($settingsPage)));
    $category = translationFieldsOf(CategoryForm::configure(Schema::make($groupPage)));
    $tag = translationFieldsOf(TagForm::configure(Schema::make($groupPage)));
    $group = translationFieldsOf(RatingGroupForm::configure(Schema::make($groupPage)));
    $option = translationFieldsOf($optionsManager->form(Schema::make($optionsManager)));

    foreach ($fields['project_settings'] as $field => $limits) {
        expect($settings["data.{$field}_translations.{$target}"] ?? $settings["{$field}_translations.{$target}"])->toBe($limits, $field);
    }

    foreach ($fields['static_pages'] as $field => $limits) {
        expect($settings["data.static_pages.about.{$target}.{$field}"] ?? $settings["static_pages.about.{$target}.{$field}"])->toBe($limits, $field);
    }

    expect($category["name_translations.{$target}"])->toBe($fields['categories']['name'])
        ->and($tag["name_translations.{$target}"])->toBe($fields['tags']['name'])
        ->and($group["label_translations.{$target}"])->toBe($fields['rating_groups']['label'])
        ->and($group["description_translations.{$target}"])->toBe($fields['rating_groups']['description'])
        ->and($option["label_translations.{$target}"])->toBe($fields['rating_options']['label'])
        ->and($option["description_translations.{$target}"])->toBe($fields['rating_options']['description']);
});

it('translates the same settings and page fields the repository ships', function () {
    expect(array_keys(ProjectTranslationCatalog::FIELDS['project_settings']))->toBe(PresetSettingsBuilder::TRANSLATABLE)
        ->and(array_keys(ProjectTranslationCatalog::FIELDS['static_pages']))->toBe(RepositoryTranslations::STATIC_PAGE_FIELDS)
        ->and(array_keys(ProjectTranslationCatalog::FIELDS))->toBe(array_map(fn (ProjectContentSection $section): string => $section->value, ProjectContentSection::cases()));
});

// Reading ------------------------------------------------------------------------

it('runs on the bootstrap until the project has a settings row', function () {
    expect(ProjectSettings::query()->exists())->toBeFalse();

    $units = catalogUnits();

    expect($units['project_settings:site_name']->reference)->toBe('RateGuru')
        ->and($units['static_pages:about:title']->reference)->toBe(config('static-pages.defaults.about.en.title'));
});

it('reads all content with one query per section, however much of it there is', function () {
    ProjectSettings::factory()->create();
    $seed = function (int $count): void {
        Category::factory()->count($count)->create(['is_active' => true]);
        Tag::factory()->count($count)->create();
        RatingGroup::factory()->count($count)->create(['is_active' => true])
            ->each(fn (RatingGroup $group) => RatingOption::factory()->count(2)->for($group, 'group')->create(['is_active' => true]));
    };
    $queries = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        translationCatalog()->units();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    $seed(1);
    $few = $queries();
    $seed(12);
    $many = $queries();

    // The settings row, categories, rating groups, options and their groups, tags.
    expect($few)->toBe($many)
        ->and($many)->toBeLessThanOrEqual(6)
        ->and(count(catalogUnits()))->toBeGreaterThan(13 * 5);
});
