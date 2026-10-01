<?php

use App\Filament\Pages\LanguagesPage;
use App\Models\Category;
use App\Models\ProjectSettings;
use App\Models\User;
use App\Support\Locale\LocaleManager;
use App\Support\Settings\PresetSettingsBuilder;
use App\Support\Settings\ProjectSettingsManager;
use App\Support\Translations\TranslationCatalogInspector;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;

/**
 * The Languages page: every installed language, whether it is offered, and how
 * complete it is — and the one place offered languages and the default are
 * changed, always through UpdateProjectLocaleSettingsAction.
 */
function languagesPage(): mixed
{
    return Livewire::test(LanguagesPage::class);
}

function offeredLocales(): array
{
    app(ProjectSettingsManager::class)->flush();

    return app(LocaleManager::class)->enabledCodes();
}

/** Project settings with every translatable field translated into these languages. */
function settingsTranslatedInto(array $locales): void
{
    $attributes = [];

    foreach (PresetSettingsBuilder::TRANSLATABLE as $field) {
        $attributes[$field] = "{$field} text";
        $attributes["{$field}_translations"] = collect($locales)->mapWithKeys(fn (string $locale): array => [$locale => "{$field} in {$locale}"])->all();
    }

    ProjectSettings::query()->update(collect($attributes)->map(fn (mixed $value): mixed => is_array($value) ? json_encode($value) : $value)->all());
    app(ProjectSettingsManager::class)->flush();
}

/**
 * Points the inspector at a copy of the catalogs with one of this language's
 * files removed — a broken release on a server.
 */
function breakCatalogsOf(string $locale): void
{
    $root = sys_get_temp_dir().'/broken-lang-'.uniqid('', true);
    File::copyDirectory(lang_path(), $root);
    File::delete("{$root}/{$locale}/ui.php");
    test()->brokenLangRoot = $root;

    app()->instance(TranslationCatalogInspector::class, new TranslationCatalogInspector($root));
}

beforeEach(function () {
    ProjectSettings::factory()->create();
    $this->actingAs(User::factory()->admin()->create());
});

afterEach(function () {
    if (isset($this->brokenLangRoot)) {
        File::deleteDirectory($this->brokenLangRoot);
    }
});

it('is for administrators only', function () {
    $this->get(LanguagesPage::getUrl())->assertOk();

    $this->actingAs(User::factory()->moderator()->create())
        ->get(LanguagesPage::getUrl())
        ->assertForbidden();
});

it('lists every installed language in config order', function () {
    $page = languagesPage();
    $natives = [];

    foreach (config('locales.supported') as $code => $info) {
        $page->assertSee($info['flag'])->assertSee($info['label'])->assertSee($code);
        $natives[] = $info['native'];
    }

    $page->assertSeeInOrder($natives);
});

it('shows which languages are offered and which is the default', function () {
    [$default, $withheld] = twoTranslatedLocales();
    offerLocales(array_values(array_diff(supportedLocales(), [$withheld])), $default);

    languagesPage()
        ->assertTableActionVisible('enable', $withheld)
        ->assertTableActionHidden('disable', $withheld)
        ->assertTableActionHidden('setDefault', $default)
        ->assertTableActionVisible('setDefault', 'en')
        ->assertSee('Default');
});

it('offers the languages enabled by default while the project has chosen none', function () {
    [, $notByDefault] = twoTranslatedLocales();
    config(["locales.supported.{$notByDefault}.enabled_by_default" => false]);
    ProjectSettings::query()->update(['enabled_locales' => null]);

    languagesPage()
        ->assertTableActionVisible('enable', $notByDefault)
        ->assertTableActionVisible('disable', 'en');

    expect(offeredLocales())->not->toContain($notByDefault);
});

it('follows the project choice over the default policy once there is one', function () {
    [, $notByDefault] = twoTranslatedLocales();
    config(["locales.supported.{$notByDefault}.enabled_by_default" => false]);
    offerLocales(supportedLocales(), 'en');

    languagesPage()->assertTableActionVisible('disable', $notByDefault);

    expect(offeredLocales())->toContain($notByDefault);
});

it('enables a language without asking when its project content is complete', function () {
    [, $withheld] = twoTranslatedLocales();
    offerLocales(array_values(array_diff(supportedLocales(), [$withheld])), 'en');
    settingsTranslatedInto(supportedLocales());

    languagesPage()->callTableAction('enable', $withheld)->assertNotified(config("locales.supported.{$withheld}.label").' enabled');

    expect(offeredLocales())->toContain($withheld)
        ->and(app(LocaleManager::class)->projectDefault())->toBe('en');
});

it('warns before enabling a language with missing project translations, then enables it', function () {
    [, $withheld] = twoTranslatedLocales();
    offerLocales(array_values(array_diff(supportedLocales(), [$withheld])), 'en');
    Category::factory()->create(['slug' => 'georgian-food', 'name' => 'Georgian food', 'name_translations' => null, 'is_active' => true]);

    languagesPage()
        ->mountTableAction('enable', $withheld)
        ->assertMountedActionModalSee(['missing project translations. Visitors may see fallback content.', 'Enable anyway'])
        ->callMountedTableAction();

    expect(offeredLocales())->toContain($withheld);
});

it('refuses to enable a language whose application translations are broken', function () {
    [, $withheld] = twoTranslatedLocales();
    offerLocales(array_values(array_diff(supportedLocales(), [$withheld])), 'en');
    breakCatalogsOf($withheld);

    languagesPage()
        ->assertTableActionDisabled('enable', $withheld)
        ->assertSee('Fix the release first.')
        ->callTableAction('enable', $withheld);

    expect(offeredLocales())->not->toContain($withheld);
});

it('disables an offered language', function () {
    [$default, $other] = twoTranslatedLocales();
    offerLocales(supportedLocales(), $default);

    languagesPage()->callTableAction('disable', $other);

    expect(offeredLocales())->not->toContain($other)
        ->and(app(LocaleManager::class)->projectDefault())->toBe($default);
});

it('will not disable the default language', function () {
    [$default] = twoTranslatedLocales();
    offerLocales(supportedLocales(), $default);

    languagesPage()
        ->assertTableActionDisabled('disable', $default)
        ->assertSee('Set another default language first.')
        ->callTableAction('disable', $default);

    expect(offeredLocales())->toContain($default)
        ->and(app(LocaleManager::class)->projectDefault())->toBe($default);
});

it('will not disable the last offered language', function () {
    [$only] = twoTranslatedLocales();
    offerLocales([$only], $only);

    languagesPage()->assertTableActionDisabled('disable', $only);

    expect(offeredLocales())->toBe([$only]);
});

it('makes an offered language the default', function () {
    [$default, $next] = twoTranslatedLocales();
    offerLocales(supportedLocales(), $default);

    languagesPage()->callTableAction('setDefault', $next);

    expect(app(LocaleManager::class)->projectDefault())->toBe($next)
        ->and(offeredLocales())->toBe(supportedLocales());
});

it('offers no way to make a disabled language the default', function () {
    [$default, $withheld] = twoTranslatedLocales();
    offerLocales(array_values(array_diff(supportedLocales(), [$withheld])), $default);

    languagesPage()->assertTableActionHidden('setDefault', $withheld);
});

it('lists what is missing, by section, with a link to the editor that manages it', function () {
    [$target] = twoTranslatedLocales();
    $category = Category::factory()->create(['slug' => 'georgian-food', 'name' => 'Georgian food', 'name_translations' => null, 'is_active' => true]);

    languagesPage()
        ->mountTableAction('missingTranslations', $target)
        ->assertMountedActionModalSee(['Categories', 'Georgian food → name', 'Project Settings'])
        ->assertMountedActionModalSeeHtml(e(route('filament.admin.resources.categories.edit', ['record' => $category])));
});

it('only reads the database when it is opened', function () {
    [$target] = twoTranslatedLocales();
    $category = Category::factory()->create(['slug' => 'landscape', 'name' => 'Landscape', 'name_translations' => null, 'is_active' => true]);
    ProjectSettings::query()->update(['active_preset_key' => 'nature']);

    languagesPage()->assertOk();
    $this->get(LanguagesPage::getUrl())->assertOk();

    expect($category->fresh()->name_translations)->toBeNull();
});
