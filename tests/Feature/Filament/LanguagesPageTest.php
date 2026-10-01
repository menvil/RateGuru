<?php

use App\Filament\Pages\LanguagesPage;
use App\Models\Category;
use App\Models\ProjectSettings;
use App\Models\User;
use App\Support\Locale\LocaleManager;
use App\Support\Settings\PresetSettingsBuilder;
use App\Support\Settings\ProjectSettingsManager;
use Livewire\Livewire;

/**
 * The Languages page: every installed language, whether it is offered, and how
 * complete it is — and the one place languages are enabled and disabled,
 * always through UpdateProjectLocaleSettingsAction. English is the default:
 * always enabled, with nothing to disable and no other language to promote.
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
    $attributes = projectSettingsTranslationsIn($locales);

    foreach (PresetSettingsBuilder::TRANSLATABLE as $field) {
        $attributes[$field] = "{$field} text";
    }

    ProjectSettings::query()->update(collect($attributes)->map(fn (mixed $value): mixed => is_array($value) ? json_encode($value) : $value)->all());
    app(ProjectSettingsManager::class)->flush();
}

beforeEach(function () {
    ProjectSettings::factory()->create();
    $this->actingAs(User::factory()->admin()->create());
});

afterEach(fn () => removeCatalogScratchDirectory($this));

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

// English ------------------------------------------------------------------------

it('shows English as enabled and the default, with nothing to disable', function () {
    offerEveryInstalledLocale();

    languagesPage()
        ->assertTableColumnStateSet('status', 'Enabled', 'en')
        ->assertTableActionHidden('disable', 'en')
        ->assertTableActionHidden('enable', 'en')
        ->assertSeeHtml('English is always enabled and is the default language.');
});

it('marks English as the default and no other language', function () {
    offerEveryInstalledLocale();
    $page = languagesPage()->assertTableColumnHasDescription('status', 'Default', 'en');

    foreach (translatedLocales() as $locale) {
        $page->assertTableColumnDoesNotHaveDescription('status', 'Default', $locale);
    }
});

it('has no way to make another language the default', function () {
    languagesPage()->assertTableActionDoesNotExist('setDefault');
});

// Offered languages ---------------------------------------------------------------

it('offers the languages enabled by default while the project has chosen none', function () {
    [, $notByDefault] = twoTranslatedLocales();
    config(["locales.supported.{$notByDefault}.enabled_by_default" => false]);
    ProjectSettings::query()->update(['enabled_locales' => null]);

    languagesPage()
        ->assertTableActionVisible('enable', $notByDefault)
        ->assertTableActionHidden('disable', 'en');

    expect(offeredLocales())->not->toContain($notByDefault);
});

it('follows the project choice over the default policy once there is one', function () {
    [, $notByDefault] = twoTranslatedLocales();
    config(["locales.supported.{$notByDefault}.enabled_by_default" => false]);
    offerLocales(supportedLocales());

    languagesPage()->assertTableActionVisible('disable', $notByDefault);

    expect(offeredLocales())->toContain($notByDefault);
});

// Enable ---------------------------------------------------------------------------

it('asks before enabling a language whose project content is complete, without warning about it', function () {
    [, $withheld] = twoTranslatedLocales();
    offerEveryInstalledLocaleExcept($withheld);
    settingsTranslatedInto(supportedLocales());
    $label = config("locales.supported.{$withheld}.label");

    languagesPage()
        ->mountTableAction('enable', $withheld)
        ->assertMountedActionModalSee(["Enable {$label}?", "{$label} has complete project translations and will become available to visitors.", 'Enable'])
        ->assertMountedActionModalDontSee(['0 missing', 'Enable anyway'])
        ->callMountedTableAction()
        ->assertNotified("{$label} enabled");

    expect(offeredLocales())->toContain($withheld);
});

it('warns before enabling a language with missing project translations, then enables it', function () {
    [, $withheld] = twoTranslatedLocales();
    offerEveryInstalledLocaleExcept($withheld);
    settingsTranslatedInto(supportedLocales());
    Category::factory()->create(['slug' => 'georgian-food', 'name' => 'Georgian food', 'name_translations' => null, 'is_active' => true]);
    $label = config("locales.supported.{$withheld}.label");

    languagesPage()
        ->mountTableAction('enable', $withheld)
        ->assertMountedActionModalSee(["{$label} has 1 missing project translation.", 'Visitors may see English fallback content.', 'Enable anyway'])
        ->callMountedTableAction();

    expect(offeredLocales())->toContain($withheld);
});

it('refuses to enable a language whose application translations are broken', function () {
    [, $withheld] = twoTranslatedLocales();
    offerEveryInstalledLocaleExcept($withheld);
    breakCatalogsOf($withheld);

    languagesPage()
        ->assertTableActionDisabled('enable', $withheld)
        ->assertSee('Fix the release first.')
        ->callTableAction('enable', $withheld);

    expect(offeredLocales())->not->toContain($withheld);
});

// Disable --------------------------------------------------------------------------

it('says where its visitors go, and that their choice is kept, before disabling', function () {
    [$other] = twoTranslatedLocales();
    offerEveryInstalledLocale();
    $label = config("locales.supported.{$other}.label");

    languagesPage()
        ->mountTableAction('disable', $other)
        ->assertMountedActionModalSee([
            "Disable {$label}?",
            "Visitors currently using {$label} will get their browser's language if it is enabled, otherwise English.",
            "Their {$label} preference is kept and will apply again if {$label} is enabled later.",
        ]);
});

it('disables an offered language', function () {
    [$other] = twoTranslatedLocales();
    offerEveryInstalledLocale();

    languagesPage()->callTableAction('disable', $other);

    expect(offeredLocales())->not->toContain($other)
        ->and(offeredLocales())->toContain('en');
});

// The table follows each change at once --------------------------------------------

it('shows a disabled language as disabled, with Enable, in the same response', function () {
    [$other] = twoTranslatedLocales();
    offerEveryInstalledLocale();

    languagesPage()
        ->assertTableColumnStateSet('status', 'Enabled', $other)
        ->callTableAction('disable', $other)
        ->assertTableColumnStateSet('status', 'Disabled', $other)
        ->assertTableActionVisible('enable', $other)
        ->assertTableActionHidden('disable', $other);
});

it('shows an enabled language as enabled, with Disable, in the same response', function () {
    [$other] = twoTranslatedLocales();
    offerEveryInstalledLocaleExcept($other);

    languagesPage()
        ->assertTableColumnStateSet('status', 'Disabled', $other)
        ->callTableAction('enable', $other)
        ->assertTableColumnStateSet('status', 'Enabled', $other)
        ->assertTableActionVisible('disable', $other)
        ->assertTableActionHidden('enable', $other);
});

// Missing translations ------------------------------------------------------------

it('lists what is missing, by section, with a link to the editor that manages it', function () {
    [$target] = twoTranslatedLocales();
    $category = Category::factory()->create(['slug' => 'georgian-food', 'name' => 'Georgian food', 'name_translations' => null, 'is_active' => true]);

    languagesPage()
        ->mountTableAction('missingTranslations', $target)
        ->assertMountedActionModalSee(['Categories', 'Georgian food → name', 'Project Settings'])
        ->assertMountedActionModalSeeHtml(e(route('filament.admin.resources.categories.edit', ['record' => $category])));
});

it('lists a static page field the project stores no text for in that language', function () {
    [$target] = twoTranslatedLocales();
    $pages = config('static-pages.defaults');
    unset($pages['about'][$target]);
    ProjectSettings::query()->firstOrFail()->update(['static_pages' => $pages]);

    languagesPage()
        ->mountTableAction('missingTranslations', $target)
        ->assertMountedActionModalSee(['Static Pages', 'About → title', 'About → content']);
});

it('only reads the database when it is opened', function () {
    [$target] = twoTranslatedLocales();
    $category = Category::factory()->create(['slug' => 'landscape', 'name' => 'Landscape', 'name_translations' => null, 'is_active' => true]);
    ProjectSettings::query()->update(['active_preset_key' => 'nature']);

    languagesPage()->assertOk();
    $this->get(LanguagesPage::getUrl())->assertOk();

    expect($category->fresh()->name_translations)->toBeNull();
});
