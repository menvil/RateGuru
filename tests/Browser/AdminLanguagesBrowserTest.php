<?php

use App\Models\ProjectSettings;
use App\Models\User;

use function Pest\Laravel\actingAs;

/**
 * The Languages screen in a real browser: the Admin v2 layout at each width,
 * the status tabs, the confirmations and the missing-translations drawer, and
 * what keyboard and screen-reader users depend on while an overlay is open —
 * measured from the page the browser built, not from markup.
 */

/** What the screen looks like right now, as the browser laid it out. */
function languagesScreen(mixed $page): array
{
    return $page->script(<<<'JS'
        (() => {
            const visible = (el) => !! el && getComputedStyle(el).display !== 'none' && el.getBoundingClientRect().width > 0
            const dialog = document.querySelector('.rg-admin-dialog-layer')
            const drawer = document.querySelector('.rg-admin-drawer-layer')

            return {
                rows: [...document.querySelectorAll('[role="table"] [role="row"][id^="rg-admin-language-"]')].map((row) => row.id.replace('rg-admin-language-', '')),
                dialog: visible(dialog),
                drawer: visible(drawer),
                focusInDialog: !! document.activeElement?.closest('.rg-admin-dialog'),
                focusInDrawer: !! document.activeElement?.closest('.rg-admin-drawer'),
                scrollLocked: getComputedStyle(document.documentElement).overflow === 'hidden',
                overflow: document.documentElement.scrollWidth > window.innerWidth,
                toasts: [...document.querySelectorAll('.rg-admin-toast-stack .rg-admin-toast')].map((toast) => toast.querySelector('.rg-admin-toast__text').textContent.trim()),
            }
        })()
    JS);
}

/** The visible text of one language's row. */
function languagesRowText(mixed $page, string $locale): string
{
    return (string) $page->script("document.getElementById('rg-admin-language-{$locale}')?.innerText ?? ''");
}

/** What has keyboard focus: its id, or its text. */
function languagesFocused(mixed $page): ?string
{
    return $page->script('document.activeElement ? (document.activeElement.id || document.activeElement.innerText.trim()) : null');
}

beforeEach(function () {
    ProjectSettings::factory()->create();
    actingAs(User::factory()->admin()->create());
});

afterEach(fn () => removeCatalogScratchDirectory($this));

it('draws Languages in Admin v2, inside the shell, with nothing left of the Filament screen', function () {
    $page = visit('/admin/languages')->resize(1440, 900)->wait(0.4);

    $page->assertVisible('#rg-admin-sidebar')
        ->assertVisible('.rg-admin-topbar')
        ->assertVisible('.rg-admin-page-header')
        ->assertVisible('.rg-admin-stats')
        ->assertVisible('nav[aria-label="Language status"]')
        ->assertVisible('[role="table"]')
        ->assertSee('Manage which installed languages are available to visitors and monitor translation coverage.')
        ->assertDontSee('How languages work');

    expect($page->script(<<<'JS'
        (() => ({
            active: document.querySelector('#rg-admin-sidebar a[aria-current="page"] .rg-admin-nav-item__label')?.textContent.trim(),
            crumbs: [...document.querySelectorAll('.rg-admin-breadcrumb__item')].map((item) => item.textContent.trim()),
            filamentTable: document.querySelectorAll('.fi-ta, .fi-ta-ctn').length,
            filamentSection: document.querySelectorAll('.fi-section').length,
            filamentHeading: document.querySelectorAll('.fi-header-heading').length,
            headings: [...document.querySelectorAll('h1')].map((h) => h.textContent.trim()),
            // The header band runs edge to edge of the main column, right under the top bar.
            band: Math.round(document.querySelector('.rg-admin-page-header').getBoundingClientRect().width),
            column: Math.round(document.querySelector('.fi-main-ctn').getBoundingClientRect().width),
            bandTop: Math.round(document.querySelector('.rg-admin-page-header').getBoundingClientRect().top),
            overflow: document.documentElement.scrollWidth > window.innerWidth,
        }))()
    JS))->toBe([
        'active' => 'Languages',
        'crumbs' => ['Localization', 'Languages'],
        'filamentTable' => 0,
        'filamentSection' => 0,
        'filamentHeading' => 0,
        'headings' => ['Languages'],
        'band' => 1140,
        'column' => 1140,
        'bandTop' => 62,
        'overflow' => false,
    ]);
});

it('keeps every column, scrolling the table inside its card rather than the page', function (int $width) {
    $page = visit('/admin/languages')->resize($width, 900)->wait(0.4);

    expect($page->script(<<<'JS'
        (() => {
            const scroll = document.querySelector('.rg-admin-table__scroll')
            const head = document.querySelector('[role="table"] [role="row"]')

            return {
                columns: head.querySelectorAll('[role="columnheader"]').length,
                gridWidth: Math.round(document.querySelector('[role="table"]').getBoundingClientRect().width) >= 1060,
                scrolls: scroll.scrollWidth > scroll.clientWidth,
                overflow: document.documentElement.scrollWidth > window.innerWidth,
            }
        })()
    JS))->toBe(['columns' => 7, 'gridWidth' => true, 'scrolls' => true, 'overflow' => false]);
})->with([1024, 390]);

it('filters by status tab without a reload, keeping the tab in the URL and in history', function () {
    [, $withheld] = twoTranslatedLocales();
    offerEveryInstalledLocaleExcept($withheld);
    settingsTranslatedInto(supportedLocales());
    breakCatalogsOf($withheld);

    $page = visit('/admin/languages')->resize(1440, 900)->wait(0.4);
    $page->script('window.notReloaded = true');

    expect(languagesScreen($page)['rows'])->toBe(supportedLocales());

    $page->click('nav[aria-label="Language status"] a[href$="status=enabled"]')->wait(0.6);
    expect(languagesScreen($page)['rows'])->toBe(array_values(array_diff(supportedLocales(), [$withheld])));
    $page->assertQueryStringHas('status', 'enabled');

    $page->click('nav[aria-label="Language status"] a[href$="status=disabled"]')->wait(0.6);
    expect(languagesScreen($page)['rows'])->toBe([$withheld]);
    $page->assertQueryStringHas('status', 'disabled');

    $page->click('nav[aria-label="Language status"] a[href$="status=incomplete"]')->wait(0.6);
    expect(languagesScreen($page)['rows'])->toBe([$withheld]);

    $page->back()->wait(0.8);
    $page->assertQueryStringHas('status', 'disabled');
    expect(languagesScreen($page)['rows'])->toBe([$withheld]);

    $page->click('nav[aria-label="Language status"] a:not([href*="status="])')->wait(0.6);
    expect(languagesScreen($page)['rows'])->toBe(supportedLocales())
        ->and($page->script('window.notReloaded ?? false'))->toBeTrue();
    $page->assertQueryStringMissing('status');
});

it('enables a complete language after a light confirmation, and says so', function () {
    [, $withheld] = twoTranslatedLocales();
    offerEveryInstalledLocaleExcept($withheld);
    settingsTranslatedInto(supportedLocales());
    $label = config("locales.supported.{$withheld}.label");

    $page = visit('/admin/languages')->resize(1440, 900)->wait(0.4);
    $page->click("#rg-admin-language-{$withheld} .rg-admin-table__cell--end button")->wait(0.6);

    expect(languagesScreen($page))->toMatchArray(['dialog' => true, 'focusInDialog' => true, 'scrollLocked' => true]);
    $page->assertSee("Enable {$label}?")
        ->assertSee("{$label} has complete project translations and will become available to visitors.")
        ->assertDontSee('Enable anyway');

    $page->click('.rg-admin-dialog .rg-admin-button--primary')->wait(0.8);

    expect(languagesScreen($page))->toMatchArray(['dialog' => false, 'scrollLocked' => false, 'toasts' => ["{$label} enabled"]])
        ->and(languagesRowText($page, $withheld))->toContain('Enabled')->toContain('Disable')
        ->and(offeredLocales())->toContain($withheld);
});

it('warns about missing translations, and reviews them instead of enabling', function () {
    [, $withheld] = twoTranslatedLocales();
    offerEveryInstalledLocaleExcept($withheld);
    settingsTranslatedInto(supportedLocales());
    untranslatedCategory();
    $label = config("locales.supported.{$withheld}.label");

    $page = visit('/admin/languages')->resize(1440, 900)->wait(0.4);
    $page->click("#rg-admin-language-{$withheld} .rg-admin-table__cell--end button")->wait(0.6);

    $page->assertVisible('.rg-admin-dialog__icon--warning')
        ->assertSee("{$label} has 1 missing project translation.")
        ->assertSee('Visitors may see English fallback content.')
        ->assertSee('Enable anyway');

    $page->click('.rg-admin-dialog .rg-admin-dialog__footer button:first-of-type')->wait(0.8);

    expect(languagesScreen($page))->toMatchArray(['dialog' => false, 'drawer' => true, 'focusInDrawer' => true, 'scrollLocked' => true])
        ->and(languagesRowText($page, $withheld))->toContain('Disabled')
        ->and(offeredLocales())->not->toContain($withheld);

    $page->assertSee("Missing in {$label}")->assertSee('Georgian food');
});

it('disables a language after explaining what happens to its visitors, and English cannot be disabled', function () {
    [$other] = twoTranslatedLocales();
    offerEveryInstalledLocale();
    $label = config("locales.supported.{$other}.label");

    $page = visit('/admin/languages')->resize(1440, 900)->wait(0.4);

    expect(languagesRowText($page, 'en'))->toContain('Always on')->not->toContain('Disable');

    $page->click("#rg-admin-language-{$other} .rg-admin-table__cell--end button")->wait(0.6);
    $page->assertVisible('.rg-admin-dialog__icon--warning')
        ->assertSee("Their {$label} preference is kept and will apply again if {$label} is enabled later.")
        ->assertSee("Stored {$label} translations are not deleted.");

    $page->click('.rg-admin-dialog .rg-admin-button--primary')->wait(0.8);

    expect(languagesScreen($page))->toMatchArray(['dialog' => false, 'toasts' => ["{$label} disabled"]])
        ->and(languagesRowText($page, $other))->toContain('Disabled')->toContain('Enable')
        ->and(offeredLocales())->not->toContain($other);
});

it('keeps keyboard focus inside a confirmation, the page still, and closes it with Escape back to its button', function () {
    [$other] = twoTranslatedLocales();
    offerEveryInstalledLocale();

    $page = visit('/admin/languages')->resize(1440, 900)->wait(0.4);
    $trigger = "#rg-admin-language-{$other} .rg-admin-table__cell--end button";

    $page->keys($trigger, 'Enter')->wait(0.6);

    expect(languagesScreen($page))->toMatchArray(['dialog' => true, 'focusInDialog' => true, 'scrollLocked' => true])
        ->and(languagesFocused($page))->toBe('Cancel');

    // Tab and Shift+Tab go round the dialog, never out of it.
    foreach (['Tab', 'Tab', 'Tab', 'Tab', 'Shift+Tab', 'Shift+Tab', 'Shift+Tab'] as $key) {
        $page->keys(':focus', $key);
        expect(languagesScreen($page)['focusInDialog'])->toBeTrue();
    }

    // Nothing behind it can take focus, not even the shell's search shortcut.
    $page->script("document.getElementById('rg-admin-search').focus()");
    $page->wait(0.1);
    expect(languagesScreen($page)['focusInDialog'])->toBeTrue();
    $page->keys(':focus', 'Control+k')->wait(0.2);
    expect(languagesScreen($page)['focusInDialog'])->toBeTrue()
        ->and($page->script('document.querySelector(".rg-admin-topbar").closest("[aria-hidden=true]") !== null'))->toBeTrue();

    $page->keys(':focus', 'Escape')->wait(0.6);

    expect(languagesScreen($page))->toMatchArray(['dialog' => false, 'scrollLocked' => false])
        ->and($page->script('document.activeElement === document.querySelector('.json_encode($trigger).')'))->toBeTrue()
        ->and($page->script('document.querySelector(".rg-admin-topbar").closest("[aria-hidden=true]") === null'))->toBeTrue()
        ->and(offeredLocales())->toContain($other);
});

it('closes a confirmation from its scrim', function () {
    [$other] = twoTranslatedLocales();
    offerEveryInstalledLocale();

    $page = visit('/admin/languages')->resize(1440, 900)->wait(0.4);
    $page->click("#rg-admin-language-{$other} .rg-admin-table__cell--end button")->wait(0.6);

    // A press on the scrim itself, below the dialog.
    $page->script(<<<'JS'
        (() => {
            const layer = document.querySelector('.rg-admin-dialog-layer')
            const box = layer.getBoundingClientRect()
            layer.dispatchEvent(new MouseEvent('mousedown', { bubbles: true, clientX: box.width / 2, clientY: box.height - 10 }))
        })()
    JS);
    $page->wait(0.6);

    expect(languagesScreen($page)['dialog'])->toBeFalse();
});

it('opens the missing-translations drawer from the row, 480 wide, and closes it every way back to the row', function () {
    [$target] = twoTranslatedLocales();
    $category = untranslatedCategory();
    $label = config("locales.supported.{$target}.label");
    $trigger = "#rg-admin-language-{$target} .rg-admin-languages__missing";

    $page = visit('/admin/languages')->resize(1440, 900)->wait(0.4);
    $page->click($trigger)->wait(0.6);

    expect(languagesScreen($page))->toMatchArray(['drawer' => true, 'focusInDrawer' => true, 'scrollLocked' => true])
        ->and($page->script(<<<'JS'
            (() => {
                const drawer = document.querySelector('.rg-admin-drawer')
                const box = drawer.getBoundingClientRect()

                return {
                    width: Math.round(box.width),
                    right: Math.round(window.innerWidth - box.right),
                    scrim: getComputedStyle(document.querySelector('.rg-admin-drawer-scrim')).display !== 'none',
                    name: document.getElementById(drawer.getAttribute('aria-labelledby')).textContent.trim(),
                    sections: [...drawer.querySelectorAll('section h3')].map((h) => h.textContent.trim()),
                }
            })()
        JS))->toMatchArray(['width' => 480, 'right' => 0, 'scrim' => true, 'name' => "Missing in {$label}"]);

    expect($page->script('[...document.querySelectorAll(".rg-admin-drawer section h3")].map((h) => h.textContent.trim())'))
        ->toContain('Project Settings', 'Categories')
        ->and($page->script('document.querySelector(".rg-admin-drawer a[href*=\"/categories/'.$category->id.'/edit\"]") !== null'))->toBeTrue();

    // Escape, back to the row.
    $page->keys(':focus', 'Escape')->wait(0.6);
    expect(languagesScreen($page))->toMatchArray(['drawer' => false, 'scrollLocked' => false])
        ->and($page->script('document.activeElement === document.querySelector('.json_encode($trigger).')'))->toBeTrue();

    // The close button.
    $page->click($trigger)->wait(0.6);
    $page->click('.rg-admin-drawer__header button[aria-label="Close"]')->wait(0.6);
    expect(languagesScreen($page)['drawer'])->toBeFalse();

    // The scrim.
    $page->click($trigger)->wait(0.6);
    $page->script("document.querySelector('.rg-admin-drawer-scrim').dispatchEvent(new MouseEvent('mousedown', { bubbles: true }))");
    $page->wait(0.6);
    expect(languagesScreen($page)['drawer'])->toBeFalse();

    // Tab stays inside the drawer.
    $page->click($trigger)->wait(0.6);
    foreach (range(1, 12) as $press) {
        $page->keys(':focus', 'Tab');
    }
    expect(languagesScreen($page)['focusInDrawer'])->toBeTrue();
});

it('fits the drawer to a phone without the page scrolling sideways', function () {
    [$target] = twoTranslatedLocales();
    untranslatedCategory();

    $page = visit('/admin/languages')->resize(390, 844)->wait(0.4);

    // The Missing column sits off screen in the table's own scroll.
    $page->script("document.querySelector('#rg-admin-language-{$target} .rg-admin-languages__missing').click()");
    $page->wait(0.8);

    expect($page->script(<<<'JS'
        (() => ({
            drawer: Math.round(document.querySelector('.rg-admin-drawer').getBoundingClientRect().width),
            viewport: window.innerWidth,
            overflow: document.documentElement.scrollWidth > window.innerWidth,
        }))()
    JS))->toBe(['drawer' => 390, 'viewport' => 390, 'overflow' => false]);
});

it('blocks enabling a language whose catalog breaks the contract, and shows why', function () {
    [, $withheld] = twoTranslatedLocales();
    offerEveryInstalledLocaleExcept($withheld);
    settingsTranslatedInto(supportedLocales());
    breakCatalogsOf($withheld);

    $page = visit('/admin/languages')->resize(1440, 900)->wait(0.4);

    expect(languagesRowText($page, $withheld))->toContain('catalog invalid')->toContain('Fix the release first.')->toContain('Catalog issue')
        ->and($page->script("document.querySelector('#rg-admin-language-{$withheld} .rg-admin-table__cell--end button').disabled"))->toBeTrue();

    $page->click("#rg-admin-language-{$withheld} .rg-admin-languages__missing")->wait(0.6);

    $page->assertSee('Application translations')
        ->assertSee('The release breaks the catalog contract for this language, so it cannot be enabled.')
        ->assertSee("{$withheld}/ui.php is missing");

    expect(offeredLocales())->not->toContain($withheld);
});

it('dismisses a toast, and shows at most three without breaking the layout', function () {
    [$other] = twoTranslatedLocales();
    offerEveryInstalledLocale();
    $label = config("locales.supported.{$other}.label");

    $page = visit('/admin/languages')->resize(1440, 900)->wait(0.4);
    $page->click("#rg-admin-language-{$other} .rg-admin-table__cell--end button")->wait(0.6);
    $page->click('.rg-admin-dialog .rg-admin-button--primary')->wait(0.8);

    expect(languagesScreen($page)['toasts'])->toBe(["{$label} disabled"])
        ->and($page->script('document.querySelector(".rg-admin-toast-stack [role=status]").textContent.trim()'))->toBe("{$label} disabled");

    $page->click('.rg-admin-toast-stack button[aria-label="Dismiss"]')->wait(0.3);
    expect(languagesScreen($page)['toasts'])->toBe([]);

    $page->script(<<<'JS'
        ['One', 'Two', 'Three', 'Four', 'Five'].forEach((message) => window.dispatchEvent(new CustomEvent('rg-admin-toast', { detail: { message } })))
    JS);
    $page->wait(0.3);

    expect(languagesScreen($page))->toMatchArray(['toasts' => ['Three', 'Four', 'Five'], 'overflow' => false])
        ->and($page->script(<<<'JS'
            (() => {
                const stack = document.querySelector('.rg-admin-toast-stack').getBoundingClientRect()
                const main = document.querySelector('.fi-main-ctn').getBoundingClientRect()

                return {
                    bottom: Math.round(window.innerHeight - stack.bottom),
                    centred: Math.abs((stack.left + stack.right) / 2 - (main.left + main.right) / 2) < 2,
                }
            })()
        JS))->toBe(['bottom' => 24, 'centred' => true]);
});

it('still closes on Escape after a click on the dialog\'s text', function () {
    [$other] = twoTranslatedLocales();
    offerEveryInstalledLocale();

    $page = visit('/admin/languages')->resize(1440, 900)->wait(0.4);
    $page->click("#rg-admin-language-{$other} .rg-admin-table__cell--end button")->wait(0.6);

    $page->click('.rg-admin-dialog__description p:first-child')->wait(0.1);
    expect(languagesScreen($page)['focusInDialog'])->toBeTrue();

    $page->keys(':focus', 'Escape')->wait(0.6);
    expect(languagesScreen($page)['dialog'])->toBeFalse();
});

it('moves focus to the page heading when the confirmed change takes the row out of its tab', function () {
    [, $withheld] = twoTranslatedLocales();
    offerEveryInstalledLocaleExcept($withheld);
    settingsTranslatedInto(supportedLocales());

    $page = visit('/admin/languages?status=disabled')->resize(1440, 900)->wait(0.4);
    $page->keys("#rg-admin-language-{$withheld} .rg-admin-table__cell--end button", 'Enter')->wait(0.6);
    $page->click('.rg-admin-dialog .rg-admin-button--primary')->wait(0.8);

    expect(languagesScreen($page))->toMatchArray(['dialog' => false, 'rows' => []])
        ->and(languagesFocused($page))->toBe('rg-admin-languages-title');
});
