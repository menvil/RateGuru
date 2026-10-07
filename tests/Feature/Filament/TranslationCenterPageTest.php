<?php

use App\Enums\UserRole;
use App\Filament\Pages\TranslationCenterPage;
use App\Filament\Resources\Categories\Pages\EditCategory;
use App\Models\Category;
use App\Models\ProjectSettings;
use App\Models\RatingGroup;
use App\Models\RatingOption;
use App\Models\Tag;
use App\Models\User;
use App\Support\TranslationEngine\Enums\TranslationErrorCode;
use App\Support\Translations\ProjectTranslationCatalog;
use App\Support\Translations\ProjectTranslationCompleteness;
use App\Support\Translations\ProjectTranslationUnit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Attributes\Url;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * Translation Center: one target language, every unit of the project's
 * content in one list, saved one unit at a time where the content already
 * keeps its translations. These tests read what the page renders and drive
 * it the way the browser does — and the way no browser would.
 */
function translationCenter(mixed $locale = null, array $query = []): Testable
{
    return Livewire::withQueryParams(array_filter(['locale' => $locale, ...$query], fn (mixed $value): bool => $value !== null))
        ->test(TranslationCenterPage::class);
}

/** One unit's row, or null when the page does not list it. */
function translationRow(Testable $page, string $unit): ?string
{
    return livewireFragment($page, "//*[@role='table']//*[@role='row'][@data-unit='{$unit}']");
}

/** @return list<string> the units the page lists, in its order */
function translationRows(Testable $page): array
{
    return array_map(
        fn (DOMElement $row): string => $row->getAttribute('data-unit'),
        iterator_to_array(livewireDom($page)->query("//*[@role='table']//*[@role='row'][@data-unit]")),
    );
}

/** @return array<string, string> the header's figures, label => value */
function translationCenterStats(Testable $page): array
{
    $xpath = livewireDom($page);
    $stats = [];

    foreach ($xpath->query("//dl[contains(@class, 'rg-admin-stats')]/div") as $stat) {
        $stats[trim($xpath->query('dt', $stat)->item(0)->textContent)] = trim((string) preg_replace('/\s+/', ' ', $xpath->query('dd', $stat)->item(0)->textContent));
    }

    return $stats;
}

/** @return list<string> the target languages the combobox offers, in its order */
function translationTargets(Testable $page): array
{
    return array_map(
        fn (DOMElement $option): string => $option->getAttribute('data-value'),
        iterator_to_array(livewireDom($page)->query("//*[@id='rg-admin-translation-target-listbox']//*[@role='option']")),
    );
}

beforeEach(function () {
    ProjectSettings::factory()->create();
    $this->actingAs(User::factory()->admin()->create());
});

afterEach(fn () => removeCatalogScratchDirectory($this));

// Access and the screen -------------------------------------------------------------

it('is for administrators only, as Languages is', function () {
    $this->get(TranslationCenterPage::getUrl())->assertOk();

    foreach ([User::factory()->moderator()->create(), User::factory()->create()] as $user) {
        $this->actingAs($user)->get(TranslationCenterPage::getUrl())->assertForbidden();
    }
});

it('stops answering an administrator who loses the role, saves included', function () {
    [$target] = twoTranslatedLocales();
    $category = untranslatedCategory();
    $page = translationCenter($target);

    auth()->user()->update(['role' => UserRole::Moderator]);

    $page->call('save', "categories:{$category->id}:name", $target, 'Text')->assertForbidden();

    expect($category->fresh()->name_translations)->toBeNull();
});

it('draws the screen in Admin v2, without Filament\'s table, sections, modals or legacy heading', function () {
    $html = $this->get(TranslationCenterPage::getUrl())->assertOk()->getContent();

    expect($html)
        ->toContain('rg-admin-translation-center__header')
        ->toContain('role="table"')
        ->toContain('rg-admin-main')
        ->toContain('Application interface strings are release-managed and not listed here.')
        ->not->toContain('fi-ta-')
        ->not->toContain('fi-section')
        ->not->toContain('fi-header-heading')
        ->not->toContain('fi-page-header-main-ctn')
        ->and(substr_count($html, '<h1'))->toBe(1);

    $source = (string) file_get_contents(app_path('Filament/Pages/TranslationCenterPage.php'));

    expect($source)->not->toContain('Filament\Notifications')->not->toContain('HasTable')->not->toContain('Filament\Actions');
});

it('sends nothing to the server while typing or filtering: no field is bound to Livewire', function () {
    $views = collect(File::allFiles(resource_path('views/filament/pages/translation-center')))
        ->map(fn (SplFileInfo $file): string => $file->getContents())
        ->push((string) file_get_contents(resource_path('views/filament/pages/translation-center.blade.php')))
        ->implode("\n");

    expect($views)->not->toContain('wire:model')
        ->and(translationCenter()->html())->not->toContain('wire:model');
});

it('offers AI translate on a missing row and an alternative on a saved one', function () {
    [$target] = twoTranslatedLocales();
    $missing = untranslatedCategory();
    $saved = Category::factory()->create(['name' => 'Dogs', 'name_translations' => [$target => 'Собаки'], 'is_active' => true]);
    $page = translationCenter($target);

    $aiButton = fn (string $unit, string $label): string => (string) livewireFragment($page, "//*[@data-unit='{$unit}']//button[contains(., '{$label}')]");

    // Each drawn as the row opens, labelled by its state; the browser keeps the label from then on.
    expect($aiButton("categories:{$missing->id}:name", 'AI translate'))->toContain('x-show="offersAi(unit)"')->not->toContain('x-cloak')
        ->and($aiButton("categories:{$saved->id}:name", 'Suggest alternative'))->toContain('x-show="offersAi(unit)"')->not->toContain('x-cloak');
});

// The target language ---------------------------------------------------------------

it('opens on the first enabled language besides English', function () {
    [$first, $second] = twoTranslatedLocales();
    offerLocales([$second]);

    expect(translationCenter()->get('locale'))->toBe($second);
});

it('opens on the first installed language when no language besides English is enabled', function () {
    offerLocales([]);

    expect(translationCenter()->get('locale'))->toBe(translatedLocales()[0]);
});

it('opens on the language the URL names, disabled or not', function () {
    [, $withheld] = twoTranslatedLocales();
    offerEveryInstalledLocaleExcept($withheld);

    $page = translationCenter($withheld);

    expect($page->get('locale'))->toBe($withheld)
        ->and(livewireFragment($page, "//*[@id='rg-admin-translation-target-trigger']"))
        ->toContain(e(config("locales.supported.{$withheld}.label")))
        ->toContain('Disabled');
});

it('falls back to the default target for a language it cannot translate into', function (mixed $locale) {
    [$first] = twoTranslatedLocales();
    offerEveryInstalledLocale();

    $page = translationCenter($locale);

    expect($page->get('locale'))->toBe($first);

    $page->set('locale', $locale);

    expect($page->get('locale'))->toBe($first);
})->with([
    'English, the reference' => ['en'],
    'not installed' => ['xx'],
    'empty' => [''],
    'a list' => [['de']],
]);

it('switches the target language on the server, keeping it in the URL', function () {
    [$first, $second] = twoTranslatedLocales();
    $page = translationCenter($first)->set('locale', $second);

    expect($page->get('locale'))->toBe($second)
        ->and(livewireFragment($page, "//*[@role='row'][contains(@class, 'rg-admin-translation-row--head')]"))
        ->toContain(e(config("locales.supported.{$second}.native")).' · '.$second);

    $attribute = collect((new ReflectionProperty(TranslationCenterPage::class, 'locale'))->getAttributes())->first()?->newInstance();

    expect($attribute)->toBeInstanceOf(Url::class)
        ->and($attribute->keep)->toBeTrue()
        ->and($attribute->history)->toBeFalse();
});

it('offers every installed language but English, enabled or not, in config order', function () {
    [, $withheld] = twoTranslatedLocales();
    offerEveryInstalledLocaleExcept($withheld);

    $page = translationCenter();
    $listbox = (string) livewireFragment($page, "//*[@id='rg-admin-translation-target-listbox']");

    expect(translationTargets($page))->toBe(translatedLocales())
        ->and($listbox)->toContain($withheld.' · disabled ·')
        ->not->toContain('English');
});

it('draws an empty screen when no language besides English is installed', function () {
    config(['locales.supported' => ['en' => config('locales.supported.en')]]);

    $page = translationCenter('de');

    expect($page->get('locale'))->toBeNull()
        ->and($page->html())->toContain('No language to translate into')->not->toContain('role="table"');
});

it('holds thirty-five languages in its one combobox', function () {
    $codes = installLanguagesUpTo(35);

    $page = translationCenter();

    expect(translationTargets($page))->toBe(array_values(array_diff($codes, ['en'])))
        ->and(livewireFragment($page, "//*[@id='rg-admin-translation-target-search']"))->toContain('placeholder="Search 34 target languages"');
});

// Header -----------------------------------------------------------------------------

it('counts the target language\'s items exactly as Languages does', function () {
    [$target] = twoTranslatedLocales();
    untranslatedCategory();
    Tag::factory()->create(['name_translations' => [$target => 'Tag']]);
    $report = app(ProjectTranslationCompleteness::class)->report($target);

    expect(translationCenterStats(translationCenter($target)))->toBe([
        'Total items' => (string) $report->required,
        'Translated' => (string) $report->translated,
        'Missing' => (string) count($report->missing),
        'Completion' => $report->percentage().'%',
    ]);
});

// Rows -------------------------------------------------------------------------------

it('lists every unit that needs a translation, in the catalog\'s order, and none that needs none', function () {
    [$target] = twoTranslatedLocales();
    untranslatedCategory();
    $group = RatingGroup::factory()->create(['description' => null, 'is_active' => true]);
    RatingOption::factory()->for($group, 'group')->create(['is_active' => true]);
    Category::factory()->create(['is_active' => false]);

    $expected = collect(app(ProjectTranslationCatalog::class)->units())
        ->filter(fn (ProjectTranslationUnit $unit): bool => $unit->requiresTranslation())
        ->pluck('id')
        ->values()
        ->all();

    expect(translationRows(translationCenter($target)))->toBe($expected)
        ->not->toContain("rating_groups:{$group->id}:description");
});

it('draws a row as the reference does: item, English and the target field with its state', function () {
    [$target] = twoTranslatedLocales();
    $category = Category::factory()->create(['slug' => 'small-pets', 'name' => 'Rabbits & rodents', 'is_active' => true]);

    $row = (string) translationRow(translationCenter($target), "categories:{$category->id}:name");
    $label = config("locales.supported.{$target}.label");

    expect($row)
        ->toContain('>Categories</span>')
        ->toContain('<div class="rg-admin-translation-row__entity">Rabbits &amp; rodents</div>')
        ->toContain('<div class="rg-admin-translation-row__field">Name</div>')
        ->toContain('categories.small-pets.name')
        ->toContain('<li class="rg-admin-constraint-chip">Max 80</li>')
        ->toContain('<li class="rg-admin-constraint-chip">Single line</li>')
        ->toContain('lang="en">Rabbits &amp; rodents</div>')
        ->toContain('17 characters')
        ->toContain('href="'.e(route('filament.admin.resources.categories.edit', ['record' => $category])).'"')
        ->toContain("<label for=\"rg-admin-tr-14-field\" class=\"rg-admin-sr-only\">{$label} translation of Rabbits &amp; rodents · Name</label>")
        ->toContain('type="text"')
        ->toContain('placeholder="Missing · type a translation or use AI translate"')
        ->toContain('aria-describedby="rg-admin-tr-14-note rg-admin-tr-14-counter rg-admin-tr-14-bulk"')
        ->toContain('Visitors see the English text')
        ->toContain('0 / 80')
        ->toContain('Save &amp; next');
});

it('opens a row on its stored translation, and a multiline unit in a textarea', function () {
    [$target] = twoTranslatedLocales();
    $group = RatingGroup::factory()->create(['description' => 'What is it like?', 'description_translations' => [$target => 'Какой он?'], 'is_active' => true]);

    $row = (string) translationRow(translationCenter($target), "rating_groups:{$group->id}:description");

    expect($row)->toContain('<li class="rg-admin-constraint-chip">Max 1,000</li>')
        ->toContain('<li class="rg-admin-constraint-chip">Multiline</li>')
        ->toContain('>Какой он?</textarea>')
        ->toContain('Stored translation');
});

it('shows each placeholder the translation has to keep', function () {
    [$target] = twoTranslatedLocales();
    $pages = ProjectSettings::findOrFail(1)->static_pages;
    $pages['contact']['en']['content'] = 'Write to {contact_email}.';
    ProjectSettings::query()->update(['static_pages' => json_encode($pages)]);

    expect(translationRow(translationCenter($target), 'static_pages:contact:content'))
        ->toContain('<li class="rg-admin-constraint-chip rg-admin-constraint-chip--placeholder">{contact_email}</li>');
});

it('links each unit to the editor of its English text', function () {
    [$target] = twoTranslatedLocales();
    $group = RatingGroup::factory()->create(['is_active' => true]);
    $option = RatingOption::factory()->for($group, 'group')->create(['is_active' => true]);
    $tag = Tag::factory()->create();
    $page = translationCenter($target);

    expect(translationRow($page, 'project_settings:site_name'))->toContain(e(route('filament.admin.pages.project-settings')))
        ->and(translationRow($page, 'static_pages:about:title'))->toContain(e(route('filament.admin.pages.project-settings')))
        ->and(translationRow($page, "tags:{$tag->id}:name"))->toContain(e(route('filament.admin.resources.tags.edit', ['record' => $tag])))
        ->and(translationRow($page, "rating_groups:{$group->id}:label"))->toContain(e(route('filament.admin.resources.rating-groups.edit', ['record' => $group])))
        // An option is edited on its group's page.
        ->and(translationRow($page, "rating_options:{$option->id}:label"))->toContain(e(route('filament.admin.resources.rating-groups.edit', ['record' => $group])));
});

it('hands the browser each unit with its stored text and limits, and nothing of other languages', function () {
    [$target, $other] = twoTranslatedLocales();
    $category = Category::factory()->create(['name' => 'Dogs', 'name_translations' => [$target => 'Собаки', $other => 'Кучета'], 'is_active' => true]);

    $client = translationCenter($target)->viewData('client');
    $unit = collect($client['units'])->firstWhere('id', "categories:{$category->id}:name");

    expect($client['locale'])->toBe($target)
        ->and($unit)->toMatchArray(['section' => 'categories', 'max' => 80, 'multiline' => false, 'placeholders' => [], 'stored' => 'Собаки'])
        ->and($unit['search'])->toContain('dogs')->toContain("categories:{$category->id}:name")
        ->and(json_encode($client))->not->toContain('Кучета');
});

it('draws the filters the URL carries, holding the rows back until the browser applies them', function () {
    [$target] = twoTranslatedLocales();
    $page = translationCenter($target, ['q' => '  dogs  ', 'section' => 'categories', 'mode' => 'missing']);

    expect(livewireFragment($page, "//*[@id='rg-admin-translation-search']"))->toContain('value="dogs"')
        ->and(livewireFragment($page, "//*[@id='rg-admin-translation-section']"))->toContain('Section:<span x-text="triggers[selected]">Categories</span>')
        ->and(livewireFragment($page, "//*[@role='radiogroup']"))->toContain('aria-checked="true" tabindex="0" x-bind:aria-checked="selected === \'missing\'')
        ->and(livewireDom($page)->query("//*[@role='table'][@x-cloak]")->length)->toBe(1);

    $plain = translationCenter($target, ['section' => 'posts', 'mode' => 'everything']);

    expect(livewireFragment($plain, "//*[@id='rg-admin-translation-section']"))->toContain('Section:<span x-text="triggers[selected]">All</span>')
        ->and(livewireDom($plain)->query("//*[@role='table'][@x-cloak]")->length)->toBe(0);
});

// Save -------------------------------------------------------------------------------

it('saves one unit and answers the browser without re-rendering the page', function () {
    [$target] = twoTranslatedLocales();
    $category = untranslatedCategory();
    $page = translationCenter($target);

    $page->call('save', "categories:{$category->id}:name", $target, '  Грузинская кухня ')
        ->assertReturned(['saved' => true, 'value' => 'Грузинская кухня']);

    // Stored, and the page still as it was: the browser keeps the drafts of every other row.
    expect($category->fresh()->name_translations)->toBe([$target => 'Грузинская кухня'])
        ->and(translationRow($page, "categories:{$category->id}:name"))->toContain('value=""');
});

it('says next to the field what is wrong with the text, and writes nothing', function () {
    [$target] = twoTranslatedLocales();
    $category = untranslatedCategory();

    translationCenter($target)
        ->call('save', "categories:{$category->id}:name", $target, str_repeat('x', 84))
        ->assertReturned(['saved' => false, 'error' => '4 over the limit', 'field' => true]);

    expect($category->fresh()->name_translations)->toBeNull();
});

it('refuses what the browser cannot be trusted with, safely', function (Closure $arguments, string $error) {
    [$target] = twoTranslatedLocales();
    $category = untranslatedCategory();

    translationCenter($target)
        ->call('save', ...$arguments($category, $target))
        ->assertReturned(fn (array $result): bool => $result['saved'] === false && $result['field'] === false && str_contains($result['error'], $error));

    expect($category->fresh()->name_translations)->toBeNull();
})->with([
    'an unknown unit' => [fn (Category $category, string $target): array => ['categories:999999:name', $target, 'Text'], 'no longer translated here'],
    'a field nobody translates' => [fn (Category $category, string $target): array => ["categories:{$category->id}:slug", $target, 'hack'], 'no longer translated here'],
    'English' => [fn (Category $category, string $target): array => ["categories:{$category->id}:name", 'en', 'Hijacked'], 'English is the reference language'],
    'a language that is not installed' => [fn (Category $category, string $target): array => ["categories:{$category->id}:name", 'xx', 'Text'], 'not installed'],
    'no text' => [fn (Category $category, string $target): array => ["categories:{$category->id}:name", $target], 'has to be text'],
    'presentation data alongside' => [fn (Category $category, string $target): array => [['section' => 'tags', 'id' => $category->id], $target, 'Text', ['max' => 9999]], 'no longer translated here'],
]);

// Context ----------------------------------------------------------------------------

it('reads one unit\'s context when the drawer opens, other languages as context only', function () {
    [$target, $other] = twoTranslatedLocales();
    $category = Category::factory()->create(['slug' => 'dogs', 'name' => 'Dogs', 'name_translations' => [$other => 'Кучета', 'en' => 'Dogs'], 'is_active' => true]);

    translationCenter($target)
        ->call('context', "categories:{$category->id}:name", $target)
        ->assertReturned(fn (array $context): bool => $context['subtitle'] === 'Dogs · Name'
            && $context['section'] === 'Categories'
            && $context['key'] === 'categories.dogs.name'
            && $context['reference'] === 'Dogs'
            && $context['max'] === '80 characters'
            && $context['format'] === 'Single line'
            && $context['placeholders'] === []
            && $context['usage'] === 'The category’s name on posts and in the upload form.'
            && $context['sourceUrl'] === route('filament.admin.resources.categories.edit', ['record' => $category])
            && str_contains($context['target'], " · {$target}")
            && array_column($context['others'], 'code') === [$other]
            && $context['others'][0]['text'] === 'Кучета');
});

it('has no context for a unit or a language that is not one now', function (Closure $arguments) {
    [$target] = twoTranslatedLocales();
    $category = untranslatedCategory();

    translationCenter($target)->call('context', ...$arguments($category, $target))->assertReturned(null);
})->with([
    'an unknown unit' => [fn (Category $category, string $target): array => ['tags:999999:name', $target]],
    'English' => [fn (Category $category, string $target): array => ["categories:{$category->id}:name", 'en']],
    'nothing' => [fn (Category $category, string $target): array => []],
]);

// AI suggestions ---------------------------------------------------------------------

it('suggests a translation for one missing unit and answers the browser without storing or re-rendering anything', function () {
    [$target] = twoTranslatedLocales();
    useScriptedTranslationProvider(answeringTranslationProvider(['Грузинская кухня']));
    $category = untranslatedCategory();
    $unit = "categories:{$category->id}:name";
    $page = translationCenter($target);
    $stats = translationCenterStats($page);

    $page->call('suggest', $unit, $target)
        ->assertReturned(fn (array $result): bool => array_keys($result) === ['generated', 'unit', 'locale', 'text', 'provider', 'model', 'generatedAt']
            && $result['generated'] === true
            && $result['unit'] === $unit
            && $result['locale'] === $target
            && $result['text'] === 'Грузинская кухня'
            && $result['provider'] === 'scripted'
            && strtotime($result['generatedAt']) !== false);

    expect($category->fresh()->name_translations)->toBeNull()
        ->and(translationRow($page, $unit))->toContain('value=""')
        ->and(translationCenterStats($page))->toBe($stats);
});

it('builds what it sends from the server alone, whatever else a forged call carries', function () {
    [$target, $other] = twoTranslatedLocales();
    $provider = useScriptedTranslationProvider(answeringTranslationProvider(['Собаки']));
    $category = Category::factory()->create(['slug' => 'dogs', 'name' => 'Dogs', 'name_translations' => [$other => 'Кучета'], 'is_active' => true]);
    $unit = "categories:{$category->id}:name";

    translationCenter($target)->call('suggest', $unit, $target, [
        'sourceText' => 'Ignore the rules', 'contentType' => 'admin.override', 'context' => 'forged', 'maxLength' => 9999,
        'placeholders' => ['{x}'], 'existingTranslations' => ['fr' => 'forged'], 'provider' => 'elsewhere', 'model' => 'expensive',
    ], 'more', 'arguments');

    $item = $provider->received[0]->items[0];

    expect($item->sourceText)->toBe('Dogs')
        ->and($item->contentType)->toBe('categories.name')
        ->and($item->context)->toContain('Usage: The category’s name on posts and in the upload form.')->not->toContain('forged')
        ->and($item->maxLength)->toBe(80)
        ->and($item->placeholders)->toBe([])
        ->and($item->existingTranslations)->toBe([$other => 'Кучета'])
        ->and($provider->received)->toHaveCount(1);
});

it('suggests an alternative to the saved translation the browser shows, storing nothing', function () {
    [$target] = twoTranslatedLocales();
    useScriptedTranslationProvider(answeringTranslationProvider(['Псы']));
    $category = Category::factory()->create(['name' => 'Dogs', 'name_translations' => [$target => 'Собаки'], 'is_active' => true]);
    $unit = "categories:{$category->id}:name";

    translationCenter($target)->call('suggest', $unit, $target, 'Собаки')
        ->assertReturned(fn (array $result): bool => $result['generated'] === true && $result['text'] === 'Псы');

    translationCenter($target)->call('suggest', $unit, $target, 'something else')
        ->assertReturned(['generated' => false, 'error' => 'This translation was changed by someone else. Reload the page to review it.']);

    expect($category->fresh()->name_translations)->toBe([$target => 'Собаки']);
});

it('answers a refused suggestion with a safe message, and asks the engine for nothing', function (Closure $arguments, string $error) {
    [$target] = twoTranslatedLocales();
    $provider = useScriptedTranslationProvider(ScriptedTranslationProvider::translating());
    $category = untranslatedCategory();

    translationCenter($target)
        ->call('suggest', ...$arguments($category, $target))
        ->assertReturned(fn (array $result): bool => $result === ['generated' => false, 'error' => $result['error']] && str_contains($result['error'], $error));

    expect($provider->received)->toBe([]);
})->with([
    'an unknown unit' => [fn (Category $category, string $target): array => ['categories:999999:name', $target], 'no longer translated here'],
    'English' => [fn (Category $category, string $target): array => ["categories:{$category->id}:name", 'en'], 'English is the reference language'],
    'a language that is not installed' => [fn (Category $category, string $target): array => ["categories:{$category->id}:name", 'xx'], 'not installed'],
    'nothing at all' => [fn (Category $category, string $target): array => [], 'not installed'],
    'a translation saved meanwhile' => [function (Category $category, string $target): array {
        $category->update(['name_translations' => [$target => 'Сохранено']]);

        return ["categories:{$category->id}:name", $target];
    }, 'saved by someone else'],
]);

it('answers an engine failure with the engine\'s safe reason, and keeps manual translation working', function () {
    [$target] = twoTranslatedLocales();
    configureOpenAiTranslation(['api_key' => null]);
    Http::fake();
    $category = untranslatedCategory();
    $unit = "categories:{$category->id}:name";
    $page = translationCenter($target);

    $page->call('suggest', $unit, $target)->assertReturned(['generated' => false, 'error' => 'Machine translation is not configured.']);
    $page->call('save', $unit, $target, 'Вручную')->assertReturned(['saved' => true, 'value' => 'Вручную']);

    Http::assertNothingSent();
});

it('says why a provider failed without passing on anything the provider said', function () {
    [$target] = twoTranslatedLocales();
    useScriptedTranslationProvider(failingTranslationProvider(TranslationErrorCode::RateLimited));
    $category = untranslatedCategory();

    translationCenter($target)->call('suggest', "categories:{$category->id}:name", $target)
        ->assertReturned(['generated' => false, 'error' => 'The translation provider is busy. Try again in a moment.']);
});

it('stops suggesting to an administrator who loses the role', function () {
    [$target] = twoTranslatedLocales();
    $provider = useScriptedTranslationProvider(ScriptedTranslationProvider::translating());
    $category = untranslatedCategory();
    $page = translationCenter($target);

    auth()->user()->update(['role' => UserRole::Moderator]);

    $page->call('suggest', "categories:{$category->id}:name", $target)->assertForbidden();

    expect($provider->received)->toBe([]);
});

it('builds no AI request while the page loads: nothing is prepared for a row nobody asked about', function () {
    [$target, $other] = twoTranslatedLocales();
    $provider = useScriptedTranslationProvider(ScriptedTranslationProvider::translating());
    Category::factory()->count(5)->create(['name_translations' => [$other => 'Кучета'], 'is_active' => true]);

    $page = translationCenter($target);

    expect($provider->received)->toBe([])
        ->and(json_encode($page->viewData('client')))->not->toContain('Business key')->not->toContain('Кучета');
});

// Generate missing --------------------------------------------------------------------

it('starts background generation from the language alone, answering without a provider call or a re-render', function () {
    Queue::fake();
    [$target] = twoTranslatedLocales();
    $provider = useScriptedTranslationProvider(ScriptedTranslationProvider::translating());
    $category = untranslatedCategory();
    $page = translationCenter($target);

    $page->call('startGeneration', $target, ['categories:999999:name'])
        ->assertReturned(fn (array $result): bool => $result['started'] === true
            && in_array("categories:{$category->id}:name", array_column($result['generation']['items'], 'unit'), true)
            && ! in_array('categories:999999:name', array_column($result['generation']['items'], 'unit'), true)
            && $result['generation']['status'] === 'queued');

    expect($provider->received)->toBe([])
        ->and(translationRow($page, "categories:{$category->id}:name"))->toContain('value=""');
});

it('answers a status read with the counts alone while nothing has changed', function () {
    Queue::fake();
    [$target] = twoTranslatedLocales();
    useScriptedTranslationProvider(ScriptedTranslationProvider::translating());
    untranslatedCategory();
    $page = translationCenter($target);
    $started = $page->call('startGeneration', $target)->effects['returns'][0]['generation'];

    $unchanged = $page->call('generationStatus', $target, $started['version'])->effects['returns'][0]['generation'];
    $full = $page->call('generationStatus', $target, null)->effects['returns'][0]['generation'];

    expect($unchanged)->toMatchArray(['batch' => $started['batch'], 'unchanged' => true, 'version' => $started['version']])
        ->and($unchanged)->not->toHaveKey('items')
        ->and($full)->toHaveKey('items');
});

it('restores nothing, and keeps working, when there is no generation or the draft store cannot be reached', function () {
    [$target] = twoTranslatedLocales();

    expect(translationCenter($target)->viewData('client')['generation'])->toBeNull();

    config(['translation.bulk.cache_store' => 'unreachable', 'cache.stores.unreachable' => ['driver' => 'redis', 'connection' => 'nowhere']]);
    $page = translationCenter($target);

    expect($page->viewData('client')['generation'])->toBeNull()
        ->and($page->call('generationStatus', $target)->effects['returns'][0])->toMatchArray(['read' => false])
        ->and($page->call('startGeneration', $target)->effects['returns'][0])->toBe(['started' => false, 'error' => 'Background generation is unavailable right now. You can still translate items one at a time.']);

    // Manual and interactive translation are untouched by it.
    $category = untranslatedCategory();
    useScriptedTranslationProvider(answeringTranslationProvider(['Грузинская кухня']));

    $page->call('save', "categories:{$category->id}:name", $target, 'Вручную')->assertReturned(['saved' => true, 'value' => 'Вручную']);
    $page->call('suggest', untranslatedCategoryId('Second'), $target)->assertReturned(fn (array $result): bool => $result['generated'] === true);
});

// What AI translate sends -------------------------------------------------------------

it('shows in the context drawer exactly what AI translate sends, from the same request', function () {
    [$target, $other] = twoTranslatedLocales();
    $provider = useScriptedTranslationProvider(answeringTranslationProvider(['Пишите на {contact_email}']));
    $category = Category::factory()->create([
        'slug' => 'contact', 'name' => 'Write to {contact_email}', 'is_active' => true,
        'name_translations' => [$other => 'Пишете на {contact_email}'],
    ]);
    $unit = "categories:{$category->id}:name";
    $page = translationCenter($target);

    $page->call('context', $unit, $target);
    $preview = $page->effects['returns'][0]['ai'];
    $page->call('suggest', $unit, $target);
    $request = $provider->received[0];
    $item = $request->items[0];

    expect($preview['target'])->toEndWith(" · {$request->targetLocale}")
        ->and($preview['source'])->toBe("English · {$item->sourceLocale}")
        ->and($preview['sourceText'])->toBe($item->sourceText)
        ->and($preview['contentType'])->toBe($item->contentType)
        ->and($preview['context'])->toBe($item->context)
        ->and($preview['max'])->toBe(number_format((int) $item->maxLength).' characters')
        ->and($preview['format'])->toBe($item->multiline ? 'Multiline' : 'Single line')
        ->and($preview['placeholders'])->toBe($item->placeholders)
        ->and(array_combine(array_column($preview['others'], 'code'), array_column($preview['others'], 'text')))->toBe($item->existingTranslations)
        ->and($preview['glossary'])->toBe([])
        ->and($preview['omitted'])->toBe(0);
});

it('never lists the target as context, and names English as the source', function () {
    [$target, $other] = twoTranslatedLocales();
    $category = Category::factory()->create(['name' => 'Dogs', 'name_translations' => [$target => 'Собаки', $other => 'Кучета'], 'is_active' => true]);

    translationCenter($target)
        ->call('context', "categories:{$category->id}:name", $target)
        ->assertReturned(fn (array $context): bool => array_column($context['ai']['others'], 'code') === [$other]
            && $context['ai']['source'] === 'English · en'
            && $context['ai']['sourceText'] === 'Dogs');
});

it('shows content in the drawer, never a provider, a model or a credential', function () {
    [$target] = twoTranslatedLocales();
    configureOpenAiTranslation(['model' => 'gpt-test-configured']);
    $category = untranslatedCategory();

    translationCenter($target)
        ->call('context', "categories:{$category->id}:name", $target)
        ->assertReturned(fn (array $context): bool => ! str_contains(json_encode($context), TRANSLATION_TEST_API_KEY)
            && ! str_contains(json_encode($context), 'gpt-test-configured')
            && ! str_contains(json_encode($context), 'openai')
            && ! str_contains(json_encode($context), 'api.openai.com'));
});

it('reads the context of one unit on its own with thirty-five languages installed', function () {
    $codes = installLanguagesUpTo(35);
    $target = $codes[1];
    $translations = [];

    foreach (array_slice($codes, 2) as $code) {
        $translations[$code] = "Dogs in {$code}";
    }

    $category = Category::factory()->create(['name' => 'Dogs', 'name_translations' => $translations, 'is_active' => true]);
    $page = translationCenter($target);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $page->call('context', "categories:{$category->id}:name", $target);
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    $context = $page->effects['returns'][0];

    expect($context['ai']['others'])->toHaveCount(33)
        ->and($context['others'])->toHaveCount(33)
        ->and(array_column($context['ai']['others'], 'code'))->not->toContain($target)
        ->and($queries)->toBeLessThan(10);
});

// One storage, two editors -----------------------------------------------------------

it('saves where the old editor reads, and shows what the old editor saved', function () {
    [$target] = twoTranslatedLocales();
    $category = untranslatedCategory();
    $unit = "categories:{$category->id}:name";

    translationCenter($target)->call('save', $unit, $target, 'Из Translation Center');

    Livewire::test(EditCategory::class, ['record' => $category->getRouteKey()])
        ->assertSet("data.name_translations.{$target}", 'Из Translation Center')
        ->set("data.name_translations.{$target}", 'Из старого редактора')
        ->call('save')
        ->assertHasNoErrors();

    expect(translationRow(translationCenter($target), $unit))->toContain('value="Из старого редактора"');
});

// Reading ----------------------------------------------------------------------------

it('reads the content with a fixed number of queries, however much there is', function () {
    [$target] = twoTranslatedLocales();
    $seed = function (int $count): void {
        Category::factory()->count($count)->create(['is_active' => true]);
        Tag::factory()->count($count)->create();
        RatingGroup::factory()->count($count)->create(['is_active' => true])
            ->each(fn (RatingGroup $group) => RatingOption::factory()->count(2)->for($group, 'group')->create(['is_active' => true]));
    };
    $queries = function () use ($target): int {
        $page = translationCenter($target);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $page->call('$refresh');
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    $seed(1);
    $few = $queries();
    $seed(10);

    expect($queries())->toBe($few);
});

/** A second untranslated category's unit id. */
function untranslatedCategoryId(string $name): string
{
    return 'categories:'.untranslatedCategory($name)->id.':name';
}
