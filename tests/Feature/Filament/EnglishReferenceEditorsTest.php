<?php

use App\Actions\Settings\SaveProjectSettingsAction;
use App\Actions\Translations\UpdateProjectTranslationAction;
use App\Filament\Pages\ProjectSettingsPage;
use App\Filament\Resources\Categories\Pages\ManageCategories;
use App\Filament\Resources\RatingGroups\Pages\CreateRatingGroup;
use App\Filament\Resources\RatingGroups\Pages\EditRatingGroup;
use App\Filament\Resources\RatingGroups\RelationManagers\OptionsRelationManager;
use App\Filament\Resources\Tags\Pages\ManageTags;
use App\Models\Category;
use App\Models\ProjectSettings;
use App\Models\RatingGroup;
use App\Models\RatingOption;
use App\Models\Tag;
use App\Models\User;
use Livewire\Livewire;

/**
 * The editors of the project's translatable content — categories, tags,
 * rating groups and their options, project settings and static pages — edit
 * English, the reference every translation is made from, and nothing else.
 * Translations are Translation Center's alone: no editor offers a field for
 * one, and saving an editor never changes, drops or writes back a stale copy
 * of one.
 */

/** @return array<string, string> one text per language besides English */
function translationsOf(string $text): array
{
    return collect(translatedLocales())->mapWithKeys(fn (string $locale): array => [$locale => "{$text} ({$locale})"])->all();
}

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
});

it('offers no translation field in any editor, for any language', function () {
    $group = RatingGroup::factory()->create();

    // Categories and tags are edited in a drawer whose fields are its draft: English, and nothing else.
    $drawers = [
        'category' => Livewire::test(ManageCategories::class)->call('create'),
        'category edit' => Livewire::test(ManageCategories::class)->call('edit', Category::factory()->create(['name_translations' => translationsOf('Dogs')])->id),
        'tag' => Livewire::test(ManageTags::class)->call('create'),
        'tag edit' => Livewire::test(ManageTags::class)->call('edit', Tag::factory()->create(['name_translations' => translationsOf('zoomies')])->id),
    ];

    foreach ($drawers as $editor => $drawer) {
        $fields = array_map(fn (DOMElement $field): string => (string) $field->getAttribute('id'), iterator_to_array(livewireDom($drawer)->query("//*[@role='dialog']//form//input | //*[@role='dialog']//form//textarea")));

        expect(array_filter(array_keys($drawer->instance()->all()), fn (string $property): bool => str_contains($property, 'translation')))->toBe([], "the {$editor} drawer holds a translation")
            ->and(array_filter($fields, fn (string $id): bool => str_ends_with($id, '-name')))->not->toBe([], "the {$editor} drawer has no name field")
            ->and(array_filter($fields, fn (string $id): bool => ! preg_match('/-(name|slug|sort)$/', $id)))->toBe([], "the {$editor} drawer has a field besides the English ones");
    }

    $editors = [
        'rating group' => [Livewire::test(CreateRatingGroup::class), ['label', 'description']],
        'rating group edit' => [Livewire::test(EditRatingGroup::class, ['record' => $group->getRouteKey()]), ['label', 'description']],
        'project settings' => [Livewire::test(ProjectSettingsPage::class), ['site_name', 'site_tagline', 'site_description', 'object_singular_name', 'object_plural_name', 'upload_cta_label', 'feed_title']],
    ];

    foreach ($editors as $editor => [$page, $fields]) {
        foreach (supportedLocales() as $locale) {
            foreach ($fields as $field) {
                $page->assertFormFieldDoesNotExist("{$field}_translations.{$locale}");
            }
        }

        expect($page->html())->not->toContain('>Translations<', "the {$editor} editor still has a Translations section");
    }

    // An option is edited in a modal: whatever is sent for a translation there is not a field of its form, and is never saved.
    $option = RatingOption::factory()->for($group, 'group')->create(['label_translations' => translationsOf('Very'), 'description_translations' => null]);
    RatingOption::factory()->count(2)->for($group, 'group')->create();

    Livewire::test(OptionsRelationManager::class, ['ownerRecord' => $group, 'pageClass' => EditRatingGroup::class])
        ->callTableAction('edit', $option, data: ['label' => 'Very', 'label_translations' => translationsOf('Forged'), 'description_translations' => translationsOf('Forged')])
        ->assertHasNoTableActionErrors();

    expect($option->fresh())->label_translations->toBe(translationsOf('Very'))->description_translations->toBeNull();
});

it('edits each static page in English only', function () {
    $settings = Livewire::test(ProjectSettingsPage::class);

    foreach (array_keys(config('static-pages.defaults')) as $page) {
        $settings->assertFormFieldExists("static_pages.{$page}.en.title")
            ->assertFormFieldExists("static_pages.{$page}.en.content");

        foreach (translatedLocales() as $locale) {
            $settings->assertFormFieldDoesNotExist("static_pages.{$page}.{$locale}.title");
        }
    }
});

it('keeps a category\'s and a tag\'s translations when their English name is edited', function () {
    $category = Category::factory()->create(['name' => 'Dogs', 'name_translations' => translationsOf('Dogs')]);
    $tag = Tag::factory()->create(['name' => 'zoomies', 'slug' => 'zoomies', 'name_translations' => translationsOf('zoomies')]);

    Livewire::test(ManageCategories::class)
        ->call('edit', $category->id)
        ->set('draftName', 'Good dogs')
        ->call('save')
        ->assertHasNoErrors();
    Livewire::test(ManageTags::class)
        ->call('edit', $tag->id)
        ->set('draftName', 'Zoomies')
        ->call('save')
        ->assertHasNoErrors();

    expect($category->fresh())->name->toBe('Good dogs')->name_translations->toBe(translationsOf('Dogs'))
        ->and($tag->fresh())->name->toBe('Zoomies')->name_translations->toBe(translationsOf('zoomies'));
});

it('keeps a rating group\'s and its options\' translations when their English is edited', function () {
    $group = RatingGroup::factory()->create(['min_options' => 2, 'label_translations' => translationsOf('Cuteness'), 'description_translations' => translationsOf('How cute')]);
    $option = RatingOption::factory()->for($group, 'group')->create(['label_translations' => translationsOf('Very'), 'description_translations' => translationsOf('Very cute')]);
    RatingOption::factory()->count(2)->for($group, 'group')->create();

    Livewire::test(EditRatingGroup::class, ['record' => $group->getRouteKey()])
        ->fillForm(['label' => 'Cuteness level', 'description' => 'How cute it is'])
        ->call('save')
        ->assertHasNoFormErrors();
    Livewire::test(OptionsRelationManager::class, ['ownerRecord' => $group, 'pageClass' => EditRatingGroup::class])
        ->callTableAction('edit', $option, data: ['key' => $option->key, 'label' => 'Very much', 'description' => 'Very cute indeed', 'is_active' => true, 'sort_order' => 1])
        ->assertHasNoTableActionErrors();

    expect($group->fresh())->label->toBe('Cuteness level')->label_translations->toBe(translationsOf('Cuteness'))->description_translations->toBe(translationsOf('How cute'))
        ->and($option->fresh())->label->toBe('Very much')->label_translations->toBe(translationsOf('Very'))->description_translations->toBe(translationsOf('Very cute'));
});

it('creates content in English, with nothing translated yet', function () {
    Livewire::test(ManageCategories::class)
        ->call('create')
        ->set('draftName', 'Desserts')
        ->call('save')
        ->assertHasNoErrors();
    Livewire::test(ManageTags::class)
        ->call('create')
        ->set('draftName', 'zoomies')
        ->call('save')
        ->assertHasNoErrors();

    expect(Category::query()->where('slug', 'desserts')->firstOrFail()->name_translations)->toBeEmpty()
        ->and(Tag::query()->where('slug', 'zoomies')->firstOrFail()->name_translations)->toBeEmpty();
});

it('keeps the project\'s translations and the other languages of its pages when the settings are saved', function () {
    $pages = config('static-pages.defaults');

    foreach (translatedLocales() as $locale) {
        $pages['about'][$locale] = ['title' => "About ({$locale})", 'content' => "About us ({$locale})"];
    }

    ProjectSettings::factory()->create(['site_name_translations' => translationsOf('Pawrank'), 'feed_title_translations' => translationsOf('Feed'), 'static_pages' => $pages]);

    Livewire::test(ProjectSettingsPage::class)
        ->set('data.site_name', 'Pawrank')
        ->set('data.static_pages.about.en.title', 'About Pawrank')
        ->call('save')
        ->assertHasNoErrors();

    $settings = ProjectSettings::findOrFail(1);

    expect($settings->site_name)->toBe('Pawrank')
        ->and($settings->site_name_translations)->toBe(translationsOf('Pawrank'))
        ->and($settings->feed_title_translations)->toBe(translationsOf('Feed'))
        ->and($settings->static_pages['about']['en']['title'])->toBe('About Pawrank');

    foreach (translatedLocales() as $locale) {
        expect($settings->static_pages['about'][$locale])->toBe(['title' => "About ({$locale})", 'content' => "About us ({$locale})"]);
    }
});

it('never writes back translations saved in Translation Center while the settings were open', function () {
    $locale = translatedLocales()[0];
    ProjectSettings::factory()->create(['static_pages' => config('static-pages.defaults')]);
    $form = Livewire::test(ProjectSettingsPage::class);

    app(UpdateProjectTranslationAction::class)->handle($this->admin, 'project_settings:site_name', $locale, 'Saved meanwhile');
    app(UpdateProjectTranslationAction::class)->handle($this->admin, 'static_pages:contact:title', $locale, 'Contact, saved meanwhile');

    $form->set('data.site_tagline', 'A new tagline')->call('save')->assertHasNoErrors();

    $settings = ProjectSettings::findOrFail(1);

    expect($settings->site_tagline)->toBe('A new tagline')
        ->and($settings->site_name_translations[$locale])->toBe('Saved meanwhile')
        ->and($settings->static_pages['contact'][$locale]['title'])->toBe('Contact, saved meanwhile');
});

it('carries no translation in the settings form\'s state', function () {
    ProjectSettings::factory()->create(['site_name_translations' => translationsOf('Pawrank'), 'static_pages' => config('static-pages.defaults')]);

    $data = Livewire::test(ProjectSettingsPage::class)->get('data');

    expect(array_filter(array_keys($data), fn (string $key): bool => str_ends_with($key, '_translations')))->toBe([])
        ->and(array_unique(array_merge(...array_map(fn (array $page): array => array_keys($page), array_values($data['static_pages'])))))->toBe(['en']);
});

it('refuses to write translations with the other project settings', function (Closure $settings) {
    expect(fn () => app(SaveProjectSettingsAction::class)->handle($settings(translatedLocales()[0])))->toThrow(InvalidArgumentException::class);
})->with([
    'a translation column' => [fn (string $locale): array => ['site_name' => 'Pawrank', 'site_name_translations' => [$locale => 'Pawrank']]],
    'a static page in another language' => [fn (string $locale): array => ['static_pages' => ['about' => [$locale => ['title' => 'About', 'content' => 'Us']]]]],
]);
