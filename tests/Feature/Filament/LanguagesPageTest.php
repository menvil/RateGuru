<?php

use App\Filament\Pages\LanguagesPage;
use App\Filament\Pages\ProjectSettingsPage;
use App\Models\Category;
use App\Models\ProjectSettings;
use App\Models\User;
use App\Support\Settings\PresetSettingsBuilder;
use App\Support\Settings\ProjectSettingsManager;
use App\Support\Translations\ProjectTranslationCompleteness;
use App\Support\Translations\TranslationCatalogInspector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * The Languages page: every installed language, whether it is offered, and how
 * complete it is — and the one place languages are enabled and disabled,
 * always through UpdateProjectLocaleSettingsAction. English is the default:
 * always enabled, with nothing to disable and no other language to promote.
 *
 * The screen is drawn in Admin v2. These tests read what it renders — the
 * header, the tabs, each language's row, the confirmation and the drawer —
 * and drive it the way its buttons do, including calls no button would make.
 */
function languagesPage(): Testable
{
    return Livewire::test(LanguagesPage::class);
}

/** The rendered page, queryable. */
function languagesDom(Testable $page): DOMXPath
{
    $dom = new DOMDocument;
    @$dom->loadHTML('<?xml encoding="utf-8"?>'.$page->html());

    return new DOMXPath($dom);
}

/**
 * The outer HTML of the first element an XPath query finds, or null — without
 * Livewire's morph markers and the whitespace between tags, so an assertion
 * can name a button by its exact text.
 */
function languagesFragment(Testable $page, string $query): ?string
{
    $xpath = languagesDom($page);
    $node = $xpath->query($query)->item(0);

    if ($node === null) {
        return null;
    }

    $html = str_replace(['<!--[if BLOCK]><![endif]-->', '<!--[if ENDBLOCK]><![endif]-->'], '', (string) $node->ownerDocument->saveHTML($node));

    return (string) preg_replace(['/>\s+/', '/\s+</'], ['>', '<'], $html);
}

/** One language's row in the table, or null when the current tab leaves it out. */
function languageRow(Testable $page, string $locale): ?string
{
    return languagesFragment($page, "//*[@role='table']//*[@role='row'][@id='rg-admin-language-{$locale}']");
}

/** The open confirmation dialog, or null. */
function languagesDialog(Testable $page): ?string
{
    return languagesFragment($page, "//*[contains(concat(' ', @class, ' '), ' rg-admin-dialog-layer ')]");
}

/** The open missing-translations drawer, or null. */
function languagesDrawer(Testable $page): ?string
{
    return languagesFragment($page, "//*[contains(concat(' ', @class, ' '), ' rg-admin-drawer-layer ')]");
}

/** The languages in the table, in the order it lists them. */
function languagesListed(Testable $page): array
{
    $codes = [];

    foreach (languagesDom($page)->query("//*[@role='table']//*[@role='row'][starts-with(@id, 'rg-admin-language-')]") as $row) {
        $codes[] = substr($row->getAttribute('id'), strlen('rg-admin-language-'));
    }

    return $codes;
}

/** The header's figures, label => value. */
function languagesStats(Testable $page): array
{
    $xpath = languagesDom($page);
    $stats = [];

    foreach ($xpath->query("//dl[contains(@class, 'rg-admin-stats')]/div") as $stat) {
        $stats[trim($xpath->query('dt', $stat)->item(0)->textContent)] = trim($xpath->query('dd', $stat)->item(0)->textContent);
    }

    return $stats;
}

/** The status tabs, label => count. */
function languagesTabs(Testable $page): array
{
    $xpath = languagesDom($page);
    $tabs = [];

    foreach ($xpath->query("//nav[@aria-label='Language status']//*[contains(@class, 'rg-admin-tab ') or @class='rg-admin-tab']") as $tab) {
        $count = trim($xpath->query(".//*[contains(@class, 'rg-admin-tab__count')]", $tab)->item(0)->textContent);
        $tabs[trim(str_replace($count, '', $tab->textContent))] = (int) $count;
    }

    return $tabs;
}

/** The label of the tab marked as current. */
function languagesActiveTab(Testable $page): ?string
{
    $xpath = languagesDom($page);
    $tab = $xpath->query("//nav[@aria-label='Language status']//a[@aria-current='page']")->item(0);

    return $tab === null ? null : trim($xpath->query('text()', $tab)->item(0)->textContent);
}

/**
 * Points the inspector at a copy of the catalogs in which this language
 * translates every line but carries one catalog English does not have — a
 * contract break that leaves the percentage at 100.
 */
function giveExtraCatalogTo(string $locale): void
{
    $root = catalogScratchDirectory();
    File::copyDirectory(lang_path(), $root);
    File::put("{$root}/{$locale}/stray.php", "<?php\n\nreturn ['line' => 'Stray'];\n");

    app()->instance(TranslationCatalogInspector::class, new TranslationCatalogInspector($root));
}

/** Points the inspector at a copy of the catalogs where one language's ui catalog is empty: an issue for every one of its lines. */
function emptyUiCatalogOf(string $locale): void
{
    $root = catalogScratchDirectory();
    File::copyDirectory(lang_path(), $root);
    File::put("{$root}/{$locale}/ui.php", "<?php\n\nreturn [];\n");

    app()->instance(TranslationCatalogInspector::class, new TranslationCatalogInspector($root));
}

/**
 * Installs made-up languages after the real ones until there are this many,
 * each with a copy of a real translation's catalogs, and one of them broken —
 * the scale the screen has to hold, from a fixture rather than from real
 * catalogs. Returns every installed code in config order.
 *
 * @return list<string>
 */
function installLanguagesUpTo(int $total): array
{
    $root = catalogScratchDirectory();
    File::copyDirectory(lang_path(), $root);
    [$source] = twoTranslatedLocales();
    $supported = config('locales.supported');

    for ($i = 0; count($supported) < $total; $i++) {
        $code = 'x'.chr(97 + intdiv($i, 26)).chr(97 + $i % 26);
        File::copyDirectory("{$root}/{$source}", "{$root}/{$code}");
        $supported[$code] = ['label' => 'Language '.strtoupper($code), 'native' => 'Native '.strtoupper($code), 'flag' => '🏳️', 'enabled_by_default' => false];
    }

    File::delete("{$root}/".array_key_last($supported).'/ui.php');
    config(['locales.supported' => $supported]);
    app()->instance(TranslationCatalogInspector::class, new TranslationCatalogInspector($root));

    return array_keys($supported);
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
        expect(languageRow($page, $code))->toContain($info['flag'], e($info['label']), ">{$code}<");
        $natives[] = $info['native'];
    }

    $page->assertSeeInOrder($natives);

    expect(languagesListed($page))->toBe(supportedLocales());
});

// The screen ---------------------------------------------------------------------

it('draws the screen in Admin v2, without Filament\'s table, sections, modals or legacy heading', function () {
    $html = $this->get(LanguagesPage::getUrl())->assertOk()->getContent();

    expect($html)
        ->toContain('rg-admin-page-header')
        ->toContain('Manage which installed languages are available to visitors and monitor translation coverage.')
        ->toContain('role="table"')
        ->toContain('rg-admin-main')
        ->not->toContain('fi-ta-')
        ->not->toContain('fi-section')
        ->not->toContain('fi-header-heading')
        ->not->toContain('fi-page-header-main-ctn')
        ->not->toContain('How languages work');

    expect(substr_count($html, '<h1'))->toBe(1);
});

it('keeps the notice that application translations ship with the release and content falls back to English', function () {
    languagesPage()
        ->assertSee('Application translations ship with the release and must be valid before a language can be enabled.')
        ->assertSee('Project content can be translated independently; missing project translations fall back to English.');
});

it('leaves Filament\'s notifications and table behind', function () {
    $source = (string) file_get_contents(app_path('Filament/Pages/LanguagesPage.php'));

    expect($source)
        ->not->toContain('Filament\Notifications')
        ->not->toContain('HasTable')
        ->not->toContain('InteractsWithTable')
        ->not->toContain('TextColumn');

    [$other] = twoTranslatedLocales();
    offerEveryInstalledLocale();

    languagesPage()->call('disableLanguage', $other)->assertNotNotified();
});

it('shares one table among however many languages there are, with no branch for any of them', function () {
    $views = collect(File::allFiles(resource_path('views/filament/pages/languages')))
        ->map(fn (SplFileInfo $file): string => $file->getContents())
        ->push((string) file_get_contents(resource_path('views/filament/pages/languages.blade.php')))
        ->push((string) file_get_contents(app_path('Filament/Pages/LanguagesPage.php')))
        ->implode("\n");

    foreach (supportedLocales() as $locale) {
        expect($views)->not->toContain("'{$locale}'")->not->toContain("\"{$locale}\"");
    }
});

// English ------------------------------------------------------------------------

it('shows English as enabled and the default, with nothing to enable or disable', function () {
    offerEveryInstalledLocale();
    $row = languageRow(languagesPage(), 'en');

    expect($row)
        ->toContain('Enabled')
        ->toContain('rg-admin-badge--success')
        ->toContain('Default')
        ->toContain('rg-admin-badge--outline')
        ->toContain('Reference language')
        ->toContain('Always on')
        ->toContain('English is the default language and is always enabled.')
        ->not->toContain('askToEnable')
        ->not->toContain('askToDisable')
        ->not->toContain('<button');
});

it('marks English as the default and no other language', function () {
    offerEveryInstalledLocale();
    $page = languagesPage();

    foreach (translatedLocales() as $locale) {
        expect(languageRow($page, $locale))->not->toContain('Default')->not->toContain('Always on')->not->toContain('Reference language');
    }
});

it('has no way to make another language the default', function () {
    $own = collect((new ReflectionClass(LanguagesPage::class))->getMethods(ReflectionMethod::IS_PUBLIC))
        ->filter(fn (ReflectionMethod $method): bool => $method->class === LanguagesPage::class)
        ->map(fn (ReflectionMethod $method): string => strtolower($method->getName()));

    expect($own->filter(fn (string $name): bool => str_contains($name, 'default'))->all())->toBe([]);

    languagesPage()->assertDontSee('Make default')->assertDontSee('Set as default');
});

it('refuses to disable English, whoever asks', function () {
    offerEveryInstalledLocale();

    languagesPage()
        ->call('askToDisable', 'en')
        ->assertDispatched('rg-admin-toast', tone: 'error', message: 'English is the default language and is always enabled.')
        ->assertSet('confirming', null)
        ->call('disableLanguage', 'en')
        ->assertDispatched('rg-admin-toast', tone: 'error', message: 'English is the default language and is always enabled.');

    expect(offeredLocales())->toContain('en');
});

// Offered languages ---------------------------------------------------------------

it('offers the languages enabled by default while the project has chosen none', function () {
    [, $notByDefault] = twoTranslatedLocales();
    config(["locales.supported.{$notByDefault}.enabled_by_default" => false]);
    ProjectSettings::query()->update(['enabled_locales' => null]);

    $page = languagesPage();

    expect(languageRow($page, $notByDefault))->toContain("askToEnable('{$notByDefault}')")->toContain('Disabled')
        ->and(languageRow($page, 'en'))->not->toContain('askToDisable');

    expect(offeredLocales())->not->toContain($notByDefault);
});

it('follows the project choice over the default policy once there is one', function () {
    [, $notByDefault] = twoTranslatedLocales();
    config(["locales.supported.{$notByDefault}.enabled_by_default" => false]);
    offerLocales(supportedLocales());

    expect(languageRow(languagesPage(), $notByDefault))->toContain("askToDisable('{$notByDefault}')")->toContain('Offered to visitors');

    expect(offeredLocales())->toContain($notByDefault);
});

// The header ----------------------------------------------------------------------

it('counts the installed and the enabled languages, English among them', function () {
    [$offered] = twoTranslatedLocales();
    offerLocales([$offered]);

    expect(languagesStats(languagesPage()))->toMatchArray([
        'Installed' => (string) count(supportedLocales()),
        'Enabled' => '2',
    ]);
});

it('weighs project translations over every language but English, disabled ones included', function () {
    [$offered, $withheld] = twoTranslatedLocales();
    offerLocales([$offered]);
    settingsTranslatedInto([$offered]);
    untranslatedCategory();

    $reports = app(ProjectTranslationCompleteness::class)->reports(supportedLocales());
    $targets = collect($reports)->except('en');
    $weighted = intdiv($targets->sum->translated * 100, $targets->sum->required);

    // The alternatives the figure must not be: with English counted, or with
    // only the offered languages counted.
    $withEnglish = intdiv(collect($reports)->sum->translated * 100, collect($reports)->sum->required);
    $offeredOnly = intdiv($reports[$offered]->translated * 100, $reports[$offered]->required);

    expect($weighted)->not->toBe($withEnglish)->not->toBe($offeredOnly)
        ->and(languagesStats(languagesPage())['Project translations'])->toBe("{$weighted}%");
});

it('counts every missing project translation except English\'s, disabled languages included', function () {
    [$offered, $withheld] = twoTranslatedLocales();
    offerLocales([$offered]);
    settingsTranslatedInto([$offered]);
    untranslatedCategory();

    $reports = app(ProjectTranslationCompleteness::class)->reports(supportedLocales());
    $missing = collect($reports)->except('en')->sum(fn ($report): int => count($report->missing));

    expect(count($reports[$withheld]->missing))->toBeGreaterThan(0)
        ->and(languagesStats(languagesPage())['Missing'])->toBe(number_format($missing));
});

it('calls project translations complete when there is nothing to translate', function () {
    ProjectSettings::query()->update([
        ...collect(PresetSettingsBuilder::TRANSLATABLE)->mapWithKeys(fn (string $field): array => [$field => ''])->all(),
        'static_pages' => json_encode([]),
    ]);
    app(ProjectSettingsManager::class)->flush();

    expect(collect(app(ProjectTranslationCompleteness::class)->reports(supportedLocales()))->sum->required)->toBe(0)
        ->and(languagesStats(languagesPage()))->toMatchArray(['Project translations' => '100%', 'Missing' => '0']);
});

// Status tabs ---------------------------------------------------------------------

it('filters the table by status, with the tab in the URL', function () {
    [$offered, $withheld] = twoTranslatedLocales();
    offerEveryInstalledLocaleExcept($withheld);

    $page = languagesPage();
    expect(languagesListed($page))->toBe(supportedLocales())
        ->and(languagesActiveTab($page))->toBe('All');

    $page->set('status', 'enabled');
    expect(languagesListed($page))->toBe(offeredLocales())
        ->and(languagesActiveTab($page))->toBe('Enabled');

    $page->set('status', 'disabled');
    expect(languagesListed($page))->toBe([$withheld])
        ->and(languagesActiveTab($page))->toBe('Disabled');

    $tabs = languagesDom($page)->query("//nav[@aria-label='Language status']//a");
    $hrefs = collect(iterator_to_array($tabs))->mapWithKeys(fn (DOMElement $tab): array => [trim($tab->firstChild->textContent) => $tab->getAttribute('href')]);

    expect($hrefs->all())->toBe([
        'All' => LanguagesPage::getUrl(),
        'Enabled' => LanguagesPage::getUrl(['status' => 'enabled']),
        'Disabled' => LanguagesPage::getUrl(['status' => 'disabled']),
        'Incomplete' => LanguagesPage::getUrl(['status' => 'incomplete']),
    ]);
});

it('opens on the tab the URL names, and on All for a status that is not a tab', function () {
    [, $withheld] = twoTranslatedLocales();
    offerEveryInstalledLocaleExcept($withheld);

    $page = Livewire::withQueryParams(['status' => 'disabled'])->test(LanguagesPage::class);
    expect(languagesListed($page))->toBe([$withheld]);

    $page = Livewire::withQueryParams(['status' => 'everything'])->test(LanguagesPage::class)->assertSet('status', 'all');
    expect(languagesListed($page))->toBe(supportedLocales());

    languagesPage()->set('status', 'nonsense')->assertSet('status', 'all');
    languagesPage()->set('status', null)->assertSet('status', 'all');
    languagesPage()->set('status', ['enabled'])->assertSet('status', 'all');
});

it('lists as incomplete a broken catalog or missing project content, and nothing else', function () {
    [$broken, $complete] = twoTranslatedLocales();
    offerEveryInstalledLocale();
    settingsTranslatedInto(supportedLocales());
    breakCatalogsOf($broken);

    $page = languagesPage()->set('status', 'incomplete');
    expect(languagesListed($page))->toBe([$broken]);

    untranslatedCategory();
    $page = languagesPage()->set('status', 'incomplete');

    expect(languagesListed($page))->toBe(translatedLocales())
        ->and(languagesListed($page))->not->toContain('en')
        ->and($complete)->toBeIn(languagesListed($page));
});

it('counts each tab over every installed language, whichever tab is open', function () {
    [$broken, $withheld] = twoTranslatedLocales();
    offerEveryInstalledLocaleExcept($withheld);
    settingsTranslatedInto(supportedLocales());
    breakCatalogsOf($broken);

    $expected = [
        'All' => count(supportedLocales()),
        'Enabled' => count(supportedLocales()) - 1,
        'Disabled' => 1,
        'Incomplete' => 1,
    ];

    $page = languagesPage();
    expect(languagesTabs($page))->toBe($expected);

    foreach (['enabled', 'disabled', 'incomplete'] as $status) {
        expect(languagesTabs($page->set('status', $status)))->toBe($expected);
    }
});

it('says when no language is incomplete, and when none is disabled', function () {
    offerEveryInstalledLocale();
    settingsTranslatedInto(supportedLocales());

    languagesPage()->set('status', 'incomplete')->assertSee('Every language is complete');
    languagesPage()->set('status', 'disabled')->assertSee('Every installed language is enabled');
});

// The table's columns -------------------------------------------------------------

it('shows a valid application catalog as valid, and a broken one as invalid whatever its percentage', function () {
    [$valid, $broken] = twoTranslatedLocales();
    offerLocales([$valid]);
    giveExtraCatalogTo($broken);

    $page = languagesPage();

    expect(languageRow($page, $valid))->toContain('100% · valid')->toContain('rg-admin-progress__bar--complete')
        ->and(languageRow($page, $broken))->toContain('100% · catalog invalid')->toContain('rg-admin-progress__bar--invalid')->not->toContain('· valid');
});

it('prints project content as a percentage and translated of required', function () {
    [$target] = twoTranslatedLocales();
    untranslatedCategory();

    $report = app(ProjectTranslationCompleteness::class)->report($target);
    $english = app(ProjectTranslationCompleteness::class)->report('en');

    expect(languageRow(languagesPage(), $target))
        ->toContain("{$report->percentage()}% · {$report->translated} of {$report->required}")
        ->and(languageRow(languagesPage(), 'en'))->toContain("100% · {$english->required} of {$english->required}");
});

it('opens the drawer from the missing count, or from a catalog issue when nothing else is missing', function () {
    [$missing, $broken] = twoTranslatedLocales();
    offerEveryInstalledLocale();
    settingsTranslatedInto(array_values(array_diff(supportedLocales(), [$missing])));
    breakCatalogsOf($broken);

    $page = languagesPage();
    $count = count(app(ProjectTranslationCompleteness::class)->report($missing)->missing);

    expect(languageRow($page, $missing))->toContain("{$count} missing")->toContain("showMissing('{$missing}')")
        ->and(languageRow($page, $broken))->toContain('Catalog issue')->toContain("showMissing('{$broken}')")
        ->and(languageRow($page, 'en'))->toContain('Nothing missing')->not->toContain('showMissing');
});

// Enable ---------------------------------------------------------------------------

it('asks before enabling a language whose project content is complete, without warning about it', function () {
    [, $withheld] = twoTranslatedLocales();
    offerEveryInstalledLocaleExcept($withheld);
    settingsTranslatedInto(supportedLocales());
    $label = config("locales.supported.{$withheld}.label");

    $page = languagesPage()->call('askToEnable', $withheld);

    expect(languagesDialog($page))
        ->toContain('role="dialog"')
        ->toContain('aria-modal="true"')
        ->toContain("Enable {$label}?")
        ->toContain("{$label} has complete project translations and will become available to visitors.")
        ->toContain('>Enable</button>')
        ->not->toContain('missing')
        ->not->toContain('Enable anyway')
        ->not->toContain('Reason');

    $page->call('enableLanguage', $withheld)
        ->assertDispatched('rg-admin-toast', message: "{$label} enabled", tone: 'success');

    expect(offeredLocales())->toContain($withheld)
        ->and(languagesDialog($page))->toBeNull();
});

it('warns before enabling a language with missing project translations, then enables it', function () {
    [, $withheld] = twoTranslatedLocales();
    offerEveryInstalledLocaleExcept($withheld);
    settingsTranslatedInto(supportedLocales());
    untranslatedCategory();
    $label = config("locales.supported.{$withheld}.label");

    $page = languagesPage()->call('askToEnable', $withheld);

    expect(languagesDialog($page))
        ->toContain('rg-admin-dialog__icon--warning')
        ->toContain("{$label} has 1 missing project translation.")
        ->toContain('Visitors may see English fallback content.')
        ->toContain('Review missing')
        ->toContain('Enable anyway');

    $page->call('enableLanguage', $withheld)
        ->assertDispatched('rg-admin-toast', message: "{$label} enabled", tone: 'success');

    expect(offeredLocales())->toContain($withheld);
});

it('reviews what is missing instead of enabling', function () {
    [, $withheld] = twoTranslatedLocales();
    offerEveryInstalledLocaleExcept($withheld);
    untranslatedCategory();

    $page = languagesPage()
        ->call('askToEnable', $withheld)
        ->call('showMissing', $withheld)
        ->assertSet('confirming', null)
        ->assertSet('missingLocale', $withheld);

    expect(languagesDialog($page))->toBeNull()
        ->and(languagesDrawer($page))->toContain('Georgian food')
        ->and(offeredLocales())->not->toContain($withheld);
});

it('refuses to enable a language whose application translations are broken', function () {
    [, $withheld] = twoTranslatedLocales();
    offerEveryInstalledLocaleExcept($withheld);
    breakCatalogsOf($withheld);
    $label = config("locales.supported.{$withheld}.label");

    $page = languagesPage();
    $row = languageRow($page, $withheld);

    // The action stays visible, disabled, with its reason tied to it.
    expect($row)
        ->toMatch('/<button\b[^>]*\sdisabled[\s>][^>]*><svg.*?<\/svg>Enable</s')
        ->toContain("aria-describedby=\"rg-admin-language-{$withheld}-blocked\"")
        ->toContain('Fix the release first.')
        ->toContain('catalog invalid')
        ->not->toContain("askToEnable('{$withheld}')");

    // A stale or forged click gets no dialog, and a forged confirmation no write.
    $page->call('askToEnable', $withheld)
        ->assertSet('confirming', null)
        ->assertDispatched('rg-admin-toast', tone: 'error', message: "{$label} cannot be enabled: its application translations break the catalog contract.")
        ->call('enableLanguage', $withheld)
        ->assertDispatched('rg-admin-toast', tone: 'error', message: "{$label} cannot be enabled: its application translations break the catalog contract.");

    expect(offeredLocales())->not->toContain($withheld);
});

it('shows a catalog that broke after the page read it, when the action refuses it', function () {
    [, $withheld] = twoTranslatedLocales();
    offerEveryInstalledLocaleExcept($withheld);

    $page = languagesPage()->call('askToEnable', $withheld);
    expect(languageRow($page, $withheld))->toContain('· valid');

    breakCatalogsOf($withheld);
    $page->call('enableLanguage', $withheld)
        ->assertDispatched('rg-admin-toast', tone: 'error');

    expect(languageRow($page, $withheld))->toContain('catalog invalid')->toContain('Fix the release first.')
        ->and(offeredLocales())->not->toContain($withheld);
});

// Disable --------------------------------------------------------------------------

it('says where its visitors go, and that their choice and translations are kept, before disabling', function () {
    [$other] = twoTranslatedLocales();
    offerEveryInstalledLocale();
    $label = config("locales.supported.{$other}.label");

    $dialog = languagesDialog(languagesPage()->call('askToDisable', $other));

    expect($dialog)
        ->toContain("Disable {$label}?")
        ->toContain("Visitors currently using {$label} will get their browser's language if it is enabled, otherwise English.")
        ->toContain("Their {$label} preference is kept and will apply again if {$label} is enabled later.")
        ->toContain("Stored {$label} translations are not deleted.")
        ->toContain("Disable {$label}</button>")
        ->toContain('rg-admin-dialog__icon--warning')
        // No reason is stored for a language change, so none is asked for.
        ->not->toContain('<textarea')
        ->not->toContain('Reason');

    expect(offeredLocales())->toContain($other);
});

it('disables an offered language', function () {
    [$other] = twoTranslatedLocales();
    offerEveryInstalledLocale();
    $label = config("locales.supported.{$other}.label");

    languagesPage()->call('disableLanguage', $other)
        ->assertDispatched('rg-admin-toast', message: "{$label} disabled", tone: 'success');

    expect(offeredLocales())->not->toContain($other)
        ->and(offeredLocales())->toContain('en');
});

it('cancels a confirmation without changing anything', function () {
    [$other] = twoTranslatedLocales();
    offerEveryInstalledLocale();

    $page = languagesPage()->call('askToDisable', $other)->call('closeConfirmation');

    expect(languagesDialog($page))->toBeNull()
        ->and(offeredLocales())->toContain($other);
});

// The table follows each change at once --------------------------------------------

it('shows a disabled language as disabled, with Enable, in the same response', function () {
    [$other] = twoTranslatedLocales();
    offerEveryInstalledLocale();

    $page = languagesPage();
    expect(languageRow($page, $other))->toContain('Enabled')->toContain("askToDisable('{$other}')");

    $page->call('askToDisable', $other)->call('disableLanguage', $other);

    expect(languageRow($page, $other))
        ->toContain('Disabled')
        ->toContain('Not offered to visitors')
        ->toContain("askToEnable('{$other}')")
        ->not->toContain("askToDisable('{$other}')")
        ->and(languagesDialog($page))->toBeNull();
});

it('shows an enabled language as enabled, with Disable, in the same response', function () {
    [$other] = twoTranslatedLocales();
    offerEveryInstalledLocaleExcept($other);

    $page = languagesPage();
    expect(languageRow($page, $other))->toContain('Disabled')->toContain("askToEnable('{$other}')");

    $page->call('askToEnable', $other)->call('enableLanguage', $other);

    expect(languageRow($page, $other))
        ->toContain('Enabled')
        ->toContain('Offered to visitors')
        ->toContain("askToDisable('{$other}')")
        ->not->toContain("askToEnable('{$other}')");
});

it('keeps the header and the tab counts in step with a change in the same response', function () {
    [$other] = twoTranslatedLocales();
    offerEveryInstalledLocale();

    $page = languagesPage()->call('disableLanguage', $other);

    expect(languagesStats($page)['Enabled'])->toBe((string) (count(supportedLocales()) - 1))
        ->and(languagesTabs($page)['Disabled'])->toBe(1);
});

// What no button would send ------------------------------------------------------

it('refuses a language that is not installed, safely, wherever it is sent', function (string $method) {
    $before = offeredLocales();

    languagesPage()
        ->call($method, unsupportedLocale())
        ->assertDispatched('rg-admin-toast', tone: 'error', message: 'That language is not installed.')
        ->assertSet('confirming', null)
        ->assertSet('missingLocale', null);

    expect(offeredLocales())->toBe($before);
})->with(['askToEnable', 'askToDisable', 'enableLanguage', 'disableLanguage', 'showMissing']);

it('refuses what is not a language code at all, without failing', function (string $method, mixed $value) {
    $before = offeredLocales();

    languagesPage()
        ->call($method, $value)
        ->assertDispatched('rg-admin-toast', tone: 'error', message: 'That language is not installed.')
        ->assertSet('confirming', null)
        ->assertSet('missingLocale', null);

    languagesPage()
        ->call($method)
        ->assertDispatched('rg-admin-toast', tone: 'error', message: 'That language is not installed.');

    expect(offeredLocales())->toBe($before);
})->with(['askToEnable', 'askToDisable', 'enableLanguage', 'disableLanguage', 'showMissing'])
    ->with(['null' => [null], 'a list' => [['de']], 'a number' => [42], 'a boolean' => [true]]);

it('does not let the browser set which confirmation or drawer is open', function (string $property) {
    [$other] = twoTranslatedLocales();

    languagesPage()->set($property, $other);
})->with(['confirming', 'confirmingLocale', 'missingLocale'])->throws(CannotUpdateLockedPropertyException::class);

it('leaves an already offered or already withheld language as it is', function () {
    [$offered, $withheld] = twoTranslatedLocales();
    offerEveryInstalledLocaleExcept($withheld);

    languagesPage()
        ->call('enableLanguage', $offered)
        ->assertDispatched('rg-admin-toast', tone: 'info')
        ->call('disableLanguage', $withheld)
        ->assertDispatched('rg-admin-toast', tone: 'info');

    expect(offeredLocales())->toContain($offered)->not->toContain($withheld);
});

// Missing translations ------------------------------------------------------------

it('lists what is missing, by section, with a link to the editor that manages it', function () {
    [$target] = twoTranslatedLocales();
    $category = untranslatedCategory();
    $label = config("locales.supported.{$target}.label");

    $drawer = languagesDrawer(languagesPage()->call('showMissing', $target));

    expect($drawer)
        ->toContain('role="dialog"')
        ->toContain('aria-modal="true"')
        ->toContain('rg-admin-drawer--wide')
        ->toContain("Missing in {$label}")
        ->toContain("{$target} · ")
        ->toContain('% project content')
        ->toContain('Categories')
        ->toContain('Georgian food')
        ->toContain('>name<')
        ->toContain('Project Settings')
        ->toContain(e(route('filament.admin.resources.categories.edit', ['record' => $category])))
        ->toContain(e(ProjectSettingsPage::getUrl()))
        ->toContain('Visitors see the English text wherever a translation is missing.')
        // There is no Translation Center yet, so nothing points at one.
        ->not->toContain('Translation Center');
});

it('keeps the sections in their own order', function () {
    [$target] = twoTranslatedLocales();
    untranslatedCategory();

    $page = languagesPage()->call('showMissing', $target);
    $sections = collect(iterator_to_array(languagesDom($page)->query("//*[contains(@class, 'rg-admin-drawer')]//section//h3")))
        ->map(fn (DOMElement $heading): string => trim($heading->textContent))
        ->all();

    $order = ['Project Settings', 'Static Pages', 'Categories', 'Rating Groups', 'Rating Options', 'Tags'];

    expect($sections)->not->toBeEmpty()
        ->and($sections)->toBe(array_values(array_intersect($order, $sections)));
});

it('lists a static page field the project stores no text for in that language', function () {
    [$target] = twoTranslatedLocales();
    $pages = config('static-pages.defaults');
    unset($pages['about'][$target]);
    ProjectSettings::query()->firstOrFail()->update(['static_pages' => $pages]);

    $drawer = languagesDrawer(languagesPage()->call('showMissing', $target));

    expect($drawer)->toContain('Static Pages')->toContain('About')->toContain('>title<')->toContain('>content<');
});

it('lists the catalog issues of a broken release, the first fifty of them', function () {
    [$target] = twoTranslatedLocales();
    emptyUiCatalogOf($target);
    $issues = app(TranslationCatalogInspector::class)->inspect($target)->issues;

    expect(count($issues))->toBeGreaterThan(LanguagesPage::LISTED_ISSUES);

    $drawer = languagesDrawer(languagesPage()->call('showMissing', $target));

    expect($drawer)
        ->toContain('Application translations')
        ->toContain('The release breaks the catalog contract for this language, so it cannot be enabled.')
        ->toContain($issues[0]->message)
        ->toContain($issues[LanguagesPage::LISTED_ISSUES - 1]->message)
        ->not->toContain($issues[LanguagesPage::LISTED_ISSUES]->message)
        ->toContain('… and '.(count($issues) - LanguagesPage::LISTED_ISSUES).' more');
});

it('says so when every piece of project content has a translation', function () {
    [$target] = twoTranslatedLocales();
    settingsTranslatedInto(supportedLocales());

    expect(languagesDrawer(languagesPage()->call('showMissing', $target)))
        ->toContain('Every piece of project content has a translation.');
});

it('closes the drawer', function () {
    [$target] = twoTranslatedLocales();

    $page = languagesPage()->call('showMissing', $target)->call('closeMissing');

    expect(languagesDrawer($page))->toBeNull();
});

it('only reads the database when it is opened', function () {
    [$target] = twoTranslatedLocales();
    $category = Category::factory()->create(['slug' => 'landscape', 'name' => 'Landscape', 'name_translations' => null, 'is_active' => true]);
    ProjectSettings::query()->update(['active_preset_key' => 'nature']);

    languagesPage()->call('showMissing', $target)->assertOk();
    $this->get(LanguagesPage::getUrl())->assertOk();

    expect($category->fresh()->name_translations)->toBeNull();
});

it('reads the project content once per render, however many parts of the screen use it', function () {
    [$target] = twoTranslatedLocales();
    untranslatedCategory();
    $page = languagesPage()->call('askToEnable', $target);

    DB::enableQueryLog();
    $page->call('showMissing', $target);
    // Identifier quoting differs by engine: "categories" on PostgreSQL and SQLite, `categories` on MariaDB.
    $categoryReads = collect(DB::getQueryLog())->filter(fn (array $query): bool => preg_match('/from\s+["`]?categories["`]?(\s|$)/i', $query['query']) === 1);

    expect(languagesDrawer($page))->not->toBeNull()
        ->and($categoryReads)->toHaveCount(1);
});

// Scale ----------------------------------------------------------------------------

it('holds thirty-five languages in one table, in config order', function () {
    $codes = installLanguagesUpTo(35);
    offerLocales(array_slice($codes, 0, 10));

    $page = languagesPage();
    $html = $page->html();

    expect($codes)->toHaveCount(35)
        ->and(languagesListed($page))->toBe($codes)
        ->and(substr_count($html, 'role="table"'))->toBe(1)
        ->and(languagesDom($page)->query("//*[@role='table']//*[@role='row']")->length)->toBe(36)
        ->and(languagesStats($page)['Installed'])->toBe('35')
        ->and(languagesTabs($page))->toMatchArray(['All' => 35, 'Enabled' => 10, 'Disabled' => 25]);

    // Every language is a row of the one table, never a card of its own.
    foreach ($codes as $code) {
        expect(languageRow($page, $code))->not->toBeNull();
    }

    // The broken copy is shown as such, and only it.
    expect(languageRow($page, end($codes)))->toContain('catalog invalid')
        ->and(languageRow($page, $codes[20]))->toContain('· valid');

    $page->set('status', 'disabled');
    expect(languagesListed($page))->toBe(array_slice($codes, 10));
});
