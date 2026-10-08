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
                // The rows a person sees: the browser's search hides the others.
                rows: [...document.querySelectorAll('[role="table"] [role="row"][id^="rg-admin-language-"]')]
                    .filter((row) => getComputedStyle(row).display !== 'none')
                    .map((row) => row.id.replace('rg-admin-language-', '')),
                query: document.getElementById('rg-admin-languages-search')?.value ?? null,
                count: document.querySelector('.rg-admin-toolbar__count')?.textContent.trim() ?? null,
                noMatch: (() => { const empty = document.querySelector('[x-show="rows.length > 0 && shown === 0"]'); return !! empty && getComputedStyle(empty).display !== 'none' })(),
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

/**
 * Opens the screen at this size once Alpine, which also wires Livewire's
 * buttons, has started on it: the search in the address is applied to the
 * rows, and the cloak they open under is lifted.
 */
function visitLanguages(string $url, int $width = 1440, int $height = 900): mixed
{
    $page = visit($url)->resize($width, $height);

    waitForScript($page, "(() => { const body = document.querySelector('.rg-admin-screen__body'); return !! body?._x_dataStack && body.querySelector('[x-cloak]') === null })()");

    return $page;
}

/**
 * Waits for the search typed into the field to be applied. The screen writes
 * it into the address in the same pass that filters the rows, so the address
 * holding all of it means the last keystroke has reached the rows too.
 */
function languagesSearched(mixed $page, string $query): void
{
    waitForScript($page, 'new URLSearchParams(location.search).get("q") ?? ""', $query);
}

/**
 * Waits for the status tab a click or Back asked for to be the one on screen.
 * A tab is a Livewire round trip: the answer marks its tab current as it
 * redraws the rows, and Livewire writes it into the address. Two tabs can
 * hold the same rows, so the rows alone cannot tell which one is open.
 */
function languagesOnTab(mixed $page, string $status): void
{
    waitForScript($page, <<<JS
        (() => {
            const current = document.querySelector('nav[aria-label="Language status"] a[aria-current="page"]')
            const tab = current ? (new URL(current.href).searchParams.get('status') ?? 'all') : null

            return tab === '{$status}' && (new URLSearchParams(location.search).get('status') ?? 'all') === '{$status}'
        })()
    JS);
}

/**
 * Waits for the confirmation ('dialog') or the drawer ('drawer') to be open as
 * a person meets it: drawn, holding keyboard focus, the page behind it still
 * and hidden from assistive technology. The server draws it; focus and the
 * inert page follow a few milliseconds later, once x-trap takes hold.
 */
function languagesOverlayOpen(mixed $page, string $overlay): void
{
    waitForScript($page, <<<JS
        (() => {
            const layer = document.querySelector('.rg-admin-{$overlay}-layer')

            return !! layer && getComputedStyle(layer).display !== 'none'
                && !! document.activeElement?.closest('.rg-admin-{$overlay}')
                && getComputedStyle(document.documentElement).overflow === 'hidden'
                && document.querySelector('.rg-admin-topbar')?.closest('[aria-hidden="true"]') !== null
        })()
    JS);
}

/**
 * Waits for the confirmation ('dialog') or the drawer ('drawer') to be gone:
 * hidden by the browser at once, then taken off the page by the server's
 * answer to being told to forget it, so nothing is still on its way.
 */
function languagesOverlayGone(mixed $page, string $overlay): void
{
    waitForScript($page, "document.querySelector('.rg-admin-{$overlay}-layer') === null");
}

beforeEach(function () {
    ProjectSettings::factory()->create();
    actingAs(User::factory()->admin()->create());
});

afterEach(fn () => removeCatalogScratchDirectory($this));

it('draws Languages in Admin v2, inside the shell, with nothing left of the Filament screen', function () {
    $page = visitLanguages('/admin/languages');

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

it('fits the table to its card at every width, as a table or as one block per language, never scrolling sideways', function (int $width, string $layout) {
    [, $withheld] = twoTranslatedLocales();
    $page = visitLanguages('/admin/languages?q='.$withheld, $width);

    expect(languagesScreen($page)['rows'])->toBe([$withheld]);

    foreach ([true, false] as $searching) {
        if (! $searching) {
            $page->clear('#rg-admin-languages-search');
            languagesSearched($page, '');
        }

        expect($page->script(<<<'JS'
            (() => {
                const scroll = document.querySelector('.rg-admin-table__scroll')
                const head = document.querySelector('[role="table"] [role="row"]')
                const row = document.querySelector('[role="table"] [role="row"][id^="rg-admin-language-"]')
                const box = (selector) => row.querySelector(selector).getBoundingClientRect()

                return {
                    layout: getComputedStyle(head).display === 'none' ? 'blocks' : 'table',
                    // As blocks, the names and the action lead each language and every figure is labelled.
                    labelled: [...row.querySelectorAll('.rg-admin-languages__cell-label')].filter((label) => getComputedStyle(label).display !== 'none').length,
                    namesFirst: box('.rg-admin-languages__cell--language').top <= box('.rg-admin-languages__cell--status').top,
                    scrolls: scroll.scrollWidth > scroll.clientWidth,
                    overflow: document.documentElement.scrollWidth > window.innerWidth,
                }
            })()
        JS))->toBe(['layout' => $layout, 'labelled' => $layout === 'blocks' ? 4 : 0, 'namesFirst' => true, 'scrolls' => false, 'overflow' => false]);
    }
})->with([
    'wide' => [1440, 'table'],
    'laptop' => [1280, 'table'],
    'rail' => [1024, 'table'],
    'tablet' => [768, 'blocks'],
    'phone' => [390, 'blocks'],
]);

it('keeps the header figures on one line on a phone', function () {
    $page = visitLanguages('/admin/languages', 390, 844);

    expect($page->script(<<<'JS'
        (() => {
            const stats = [...document.querySelectorAll('.rg-admin-stats .rg-admin-stat')].map((stat) => stat.getBoundingClientRect())
            const values = [...document.querySelectorAll('.rg-admin-stats .rg-admin-stat__value')].map((value) => Math.round(value.getBoundingClientRect().bottom))

            return {
                count: stats.length,
                oneLine: stats.every((stat) => Math.abs(stat.top - stats[0].top) < 1),
                aligned: new Set(values).size === 1,
                inside: stats.every((stat) => stat.right <= window.innerWidth),
            }
        })()
    JS))->toBe(['count' => 4, 'oneLine' => true, 'aligned' => true, 'inside' => true]);
});

it('opens a language besides English in Translation Center from its name', function () {
    [$target] = twoTranslatedLocales();
    settingsTranslatedInto(supportedLocales());

    $page = visitLanguages('/admin/languages');

    expect($page->script("document.querySelector('#rg-admin-language-en .rg-admin-languages__names--link')"))->toBeNull();

    $page->click("#rg-admin-language-{$target} .rg-admin-languages__names--link");

    // A page load: Translation Center shows its rows once its own Alpine has applied the filters.
    eventually(fn () => expect($page->script('location.pathname + location.search'))->toBe("/admin/translation-center?locale={$target}")
        // Complete or not, the language's translations are there to improve.
        ->and($page->script("[...document.querySelectorAll('[data-unit]')].filter((row) => getComputedStyle(row).display !== 'none').length"))->toBeGreaterThan(0));
});

it('filters by status tab without a reload, keeping the tab in the URL and in history', function () {
    [, $withheld] = twoTranslatedLocales();
    offerEveryInstalledLocaleExcept($withheld);
    settingsTranslatedInto(supportedLocales());
    breakCatalogsOf($withheld);

    $page = visitLanguages('/admin/languages');
    $page->script('window.notReloaded = true');

    expect(languagesScreen($page)['rows'])->toBe(supportedLocales());

    $page->click('nav[aria-label="Language status"] a[href$="status=enabled"]');
    languagesOnTab($page, 'enabled');
    expect(languagesScreen($page)['rows'])->toBe(array_values(array_diff(supportedLocales(), [$withheld])));
    $page->assertQueryStringHas('status', 'enabled');

    $page->click('nav[aria-label="Language status"] a[href$="status=disabled"]');
    languagesOnTab($page, 'disabled');
    expect(languagesScreen($page)['rows'])->toBe([$withheld]);
    $page->assertQueryStringHas('status', 'disabled');

    $page->click('nav[aria-label="Language status"] a[href$="status=incomplete"]');
    languagesOnTab($page, 'incomplete');
    expect(languagesScreen($page)['rows'])->toBe([$withheld]);

    $page->back();
    languagesOnTab($page, 'disabled');
    $page->assertQueryStringHas('status', 'disabled');
    expect(languagesScreen($page)['rows'])->toBe([$withheld]);

    $page->click('nav[aria-label="Language status"] a:not([href*="status="])');
    languagesOnTab($page, 'all');
    expect(languagesScreen($page)['rows'])->toBe(supportedLocales())
        ->and($page->script('window.notReloaded ?? false'))->toBeTrue();
    $page->assertQueryStringMissing('status');
});

it('enables a complete language after a light confirmation, and says so', function () {
    [, $withheld] = twoTranslatedLocales();
    offerEveryInstalledLocaleExcept($withheld);
    settingsTranslatedInto(supportedLocales());
    $label = config("locales.supported.{$withheld}.label");

    $page = visitLanguages('/admin/languages');
    $page->click("#rg-admin-language-{$withheld} .rg-admin-table__cell--end button");
    languagesOverlayOpen($page, 'dialog');

    expect(languagesScreen($page))->toMatchArray(['dialog' => true, 'focusInDialog' => true, 'scrollLocked' => true]);
    $page->assertSee("Enable {$label}?")
        ->assertSee("{$label} has complete project translations and will become available to visitors.")
        ->assertDontSee('Enable anyway');

    $page->click('.rg-admin-dialog .rg-admin-button--primary');

    eventually(fn () => expect(languagesScreen($page))->toMatchArray(['dialog' => false, 'scrollLocked' => false, 'toasts' => ["{$label} enabled"]])
        ->and(languagesRowText($page, $withheld))->toContain('Enabled')->toContain('Disable')
        ->and(offeredLocales())->toContain($withheld));
});

it('warns about missing translations, and reviews them instead of enabling', function () {
    [, $withheld] = twoTranslatedLocales();
    offerEveryInstalledLocaleExcept($withheld);
    settingsTranslatedInto(supportedLocales());
    untranslatedCategory();
    $label = config("locales.supported.{$withheld}.label");

    $page = visitLanguages('/admin/languages');
    $page->click("#rg-admin-language-{$withheld} .rg-admin-table__cell--end button");
    languagesOverlayOpen($page, 'dialog');

    $page->assertVisible('.rg-admin-dialog__icon--warning')
        ->assertSee("{$label} has 1 missing project translation.")
        ->assertSee('Visitors may see English fallback content.')
        ->assertSee('Enable anyway');

    $page->click('.rg-admin-dialog .rg-admin-dialog__footer button:first-of-type');
    languagesOverlayOpen($page, 'drawer');

    expect(languagesScreen($page))->toMatchArray(['dialog' => false, 'drawer' => true, 'focusInDrawer' => true, 'scrollLocked' => true])
        ->and(languagesRowText($page, $withheld))->toContain('Disabled')
        ->and(offeredLocales())->not->toContain($withheld);

    $page->assertSee("Missing in {$label} — ".config("locales.supported.{$withheld}.native"))->assertSee('Georgian food');
});

it('disables a language after explaining what happens to its visitors, and English cannot be disabled', function () {
    [$other] = twoTranslatedLocales();
    offerEveryInstalledLocale();
    $label = config("locales.supported.{$other}.label");

    $page = visitLanguages('/admin/languages');

    expect(languagesRowText($page, 'en'))->toContain('Always on')->not->toContain('Disable');

    $page->click("#rg-admin-language-{$other} .rg-admin-table__cell--end button");
    languagesOverlayOpen($page, 'dialog');
    $page->assertVisible('.rg-admin-dialog__icon--warning')
        ->assertSee("Their {$label} preference is kept and will apply again if {$label} is enabled later.")
        ->assertSee("Stored {$label} translations are not deleted.");

    $page->click('.rg-admin-dialog .rg-admin-button--primary');

    eventually(fn () => expect(languagesScreen($page))->toMatchArray(['dialog' => false, 'toasts' => ["{$label} disabled"]])
        ->and(languagesRowText($page, $other))->toContain('Disabled')->toContain('Enable')
        ->and(offeredLocales())->not->toContain($other));
});

it('keeps keyboard focus inside a confirmation, the page still, and closes it with Escape back to its button', function () {
    [$other] = twoTranslatedLocales();
    offerEveryInstalledLocale();

    $page = visitLanguages('/admin/languages');
    $trigger = "#rg-admin-language-{$other} .rg-admin-table__cell--end button";

    $page->keys($trigger, 'Enter');
    languagesOverlayOpen($page, 'dialog');

    expect(languagesScreen($page))->toMatchArray(['dialog' => true, 'focusInDialog' => true, 'scrollLocked' => true])
        ->and(languagesFocused($page))->toBe('Cancel');

    // Tab and Shift+Tab go round the dialog, never out of it.
    foreach (['Tab', 'Tab', 'Tab', 'Tab', 'Shift+Tab', 'Shift+Tab', 'Shift+Tab'] as $key) {
        $page->keys(':focus', $key);
        expect(languagesScreen($page)['focusInDialog'])->toBeTrue();
    }

    // Nothing behind it can take focus, not even the shell's search shortcut.
    // Focus staying put has no event to wait for: each pause is the window in
    // which a late move would have shown.
    $page->script("document.getElementById('rg-admin-search').focus()");
    proveNothingHappensFor($page, 0.1, 'focus leaving the dialog');
    expect(languagesScreen($page)['focusInDialog'])->toBeTrue();
    // The shortcut defers its focus by no more than an Alpine $nextTick, which a tenth of a second covers many times over.
    proveNothingHappensFor($page->keys(':focus', 'Control+k'), 0.1, 'the search shortcut taking focus from the dialog');
    expect(languagesScreen($page)['focusInDialog'])->toBeTrue()
        ->and($page->script('document.querySelector(".rg-admin-topbar").closest("[aria-hidden=true]") !== null'))->toBeTrue();

    $page->keys(':focus', 'Escape');
    languagesOverlayGone($page, 'dialog');

    eventually(fn () => expect(languagesScreen($page))->toMatchArray(['dialog' => false, 'scrollLocked' => false])
        ->and($page->script('document.activeElement === document.querySelector('.json_encode($trigger).')'))->toBeTrue()
        ->and($page->script('document.querySelector(".rg-admin-topbar").closest("[aria-hidden=true]") === null'))->toBeTrue()
        ->and(offeredLocales())->toContain($other));
});

it('closes a confirmation from its scrim', function () {
    [$other] = twoTranslatedLocales();
    offerEveryInstalledLocale();

    $page = visitLanguages('/admin/languages');
    $page->click("#rg-admin-language-{$other} .rg-admin-table__cell--end button");
    languagesOverlayOpen($page, 'dialog');

    // A press on the scrim itself, below the dialog.
    $page->script(<<<'JS'
        (() => {
            const layer = document.querySelector('.rg-admin-dialog-layer')
            const box = layer.getBoundingClientRect()
            layer.dispatchEvent(new MouseEvent('mousedown', { bubbles: true, clientX: box.width / 2, clientY: box.height - 10 }))
        })()
    JS);
    languagesOverlayGone($page, 'dialog');

    expect(languagesScreen($page)['dialog'])->toBeFalse();
});

it('opens the missing-translations drawer from the row, 480 wide, and closes it every way back to the row', function () {
    [$target] = twoTranslatedLocales();
    $category = untranslatedCategory();
    $label = config("locales.supported.{$target}.label");
    $trigger = "#rg-admin-language-{$target} .rg-admin-languages__missing";

    $page = visitLanguages('/admin/languages');
    $page->click($trigger);
    languagesOverlayOpen($page, 'drawer');

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
        JS))->toMatchArray(['width' => 480, 'right' => 0, 'scrim' => true, 'name' => "Missing in {$label} — ".config("locales.supported.{$target}.native")]);

    expect($page->script('[...document.querySelectorAll(".rg-admin-drawer section h3")].map((h) => h.textContent.trim())'))
        ->toContain('Project Settings', 'Categories')
        ->and($page->script('document.querySelector(".rg-admin-drawer a[href*=\"/categories/'.$category->id.'/edit\"]") !== null'))->toBeTrue();

    // Escape, back to the row.
    $page->keys(':focus', 'Escape');
    languagesOverlayGone($page, 'drawer');
    eventually(fn () => expect(languagesScreen($page))->toMatchArray(['drawer' => false, 'scrollLocked' => false])
        ->and($page->script('document.activeElement === document.querySelector('.json_encode($trigger).')'))->toBeTrue());

    // The close button.
    $page->click($trigger);
    languagesOverlayOpen($page, 'drawer');
    $page->click('.rg-admin-drawer__header button[aria-label="Close"]');
    languagesOverlayGone($page, 'drawer');
    expect(languagesScreen($page)['drawer'])->toBeFalse();

    // The scrim.
    $page->click($trigger);
    languagesOverlayOpen($page, 'drawer');
    $page->script("document.querySelector('.rg-admin-drawer-scrim').dispatchEvent(new MouseEvent('mousedown', { bubbles: true }))");
    languagesOverlayGone($page, 'drawer');
    expect(languagesScreen($page)['drawer'])->toBeFalse();

    // Tab stays inside the drawer.
    $page->click($trigger);
    languagesOverlayOpen($page, 'drawer');
    foreach (range(1, 12) as $press) {
        $page->keys(':focus', 'Tab');
    }
    expect(languagesScreen($page)['focusInDrawer'])->toBeTrue();
});

it('fits the drawer to a phone without the page scrolling sideways', function () {
    [$target] = twoTranslatedLocales();
    untranslatedCategory();

    $page = visitLanguages('/admin/languages', 390, 844);

    $page->script("document.querySelector('#rg-admin-language-{$target} .rg-admin-languages__missing').click()");
    languagesOverlayOpen($page, 'drawer');

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

    $page = visitLanguages('/admin/languages');

    expect(languagesRowText($page, $withheld))->toContain('catalog invalid')->toContain('Fix the release first.')->toContain('Catalog issue')
        ->and($page->script("document.querySelector('#rg-admin-language-{$withheld} .rg-admin-table__cell--end button').disabled"))->toBeTrue();

    $page->click("#rg-admin-language-{$withheld} .rg-admin-languages__missing");
    languagesOverlayOpen($page, 'drawer');

    $page->assertSee('Application translations')
        ->assertSee('The release breaks the catalog contract for this language, so it cannot be enabled.')
        ->assertSee("{$withheld}/ui.php is missing");

    expect(offeredLocales())->not->toContain($withheld);
});

it('dismisses a toast, and shows at most three without breaking the layout', function () {
    [$other] = twoTranslatedLocales();
    offerEveryInstalledLocale();
    $label = config("locales.supported.{$other}.label");

    $page = visitLanguages('/admin/languages');
    $page->click("#rg-admin-language-{$other} .rg-admin-table__cell--end button");
    languagesOverlayOpen($page, 'dialog');
    $page->click('.rg-admin-dialog .rg-admin-button--primary');

    // The status region takes the message a moment after the toast shows (x-admin.ui.toast-stack).
    eventually(fn () => expect(languagesScreen($page)['toasts'])->toBe(["{$label} disabled"])
        ->and($page->script('document.querySelector(".rg-admin-toast-stack [role=status]").textContent.trim()'))->toBe("{$label} disabled"));

    $page->click('.rg-admin-toast-stack button[aria-label="Dismiss"]');
    // Well inside the toast's own 5.2 s: it is the button that took it away, not its timer.
    eventually(fn () => expect(languagesScreen($page)['toasts'])->toBe([]), 2.0);

    $page->script(<<<'JS'
        ['One', 'Two', 'Three', 'Four', 'Five'].forEach((message) => window.dispatchEvent(new CustomEvent('rg-admin-toast', { detail: { message } })))
    JS);

    eventually(fn () => expect(languagesScreen($page))->toMatchArray(['toasts' => ['Three', 'Four', 'Five'], 'overflow' => false])
        ->and($page->script(<<<'JS'
            (() => {
                const stack = document.querySelector('.rg-admin-toast-stack').getBoundingClientRect()
                const main = document.querySelector('.fi-main-ctn').getBoundingClientRect()

                return {
                    bottom: Math.round(window.innerHeight - stack.bottom),
                    centred: Math.abs((stack.left + stack.right) / 2 - (main.left + main.right) / 2) < 2,
                }
            })()
        JS))->toBe(['bottom' => 24, 'centred' => true]));
});

it('still closes on Escape after a click on the dialog\'s text', function () {
    [$other] = twoTranslatedLocales();
    offerEveryInstalledLocale();

    $page = visitLanguages('/admin/languages');
    $page->click("#rg-admin-language-{$other} .rg-admin-table__cell--end button");
    languagesOverlayOpen($page, 'dialog');

    // Focus staying in the dialog has no event to wait for: the pause is the window in which a late move would have shown.
    proveNothingHappensFor($page->click('.rg-admin-dialog__description p:first-child'), 0.1, 'focus leaving the dialog');
    expect(languagesScreen($page)['focusInDialog'])->toBeTrue();

    $page->keys(':focus', 'Escape');
    languagesOverlayGone($page, 'dialog');
    expect(languagesScreen($page)['dialog'])->toBeFalse();
});

it('moves focus to the page heading when the confirmed change takes the row out of its tab', function () {
    [, $withheld] = twoTranslatedLocales();
    offerEveryInstalledLocaleExcept($withheld);
    settingsTranslatedInto(supportedLocales());

    $page = visitLanguages('/admin/languages?status=disabled');
    $page->keys("#rg-admin-language-{$withheld} .rg-admin-table__cell--end button", 'Enter');
    languagesOverlayOpen($page, 'dialog');
    $page->click('.rg-admin-dialog .rg-admin-button--primary');

    // The heading takes focus a moment after the row and the dialog have gone (x-admin.ui.overlay).
    eventually(fn () => expect(languagesScreen($page))->toMatchArray(['dialog' => false, 'rows' => []])
        ->and(languagesFocused($page))->toBe('rg-admin-languages-title'));
});

it('opens with the search from the URL already applied', function () {
    [, $withheld] = twoTranslatedLocales();
    $native = config("locales.supported.{$withheld}.native");
    $query = mb_substr($native, 0, 3);

    $page = visitLanguages('/admin/languages?q='.rawurlencode($query));

    expect(languagesScreen($page))->toMatchArray([
        'rows' => [$withheld],
        'query' => $query,
        'count' => '1 of '.count(supportedLocales()).' installed',
        'noMatch' => false,
    ]);
});

it('filters as you type, in the page, without a single request to Livewire', function () {
    [, $withheld] = twoTranslatedLocales();
    $label = config("locales.supported.{$withheld}.label");

    $page = visitLanguages('/admin/languages');
    watchLivewireUpdates($page);

    // English name, case aside; native name; locale code.
    foreach ([mb_strtoupper(mb_substr($label, 0, 4)), config("locales.supported.{$withheld}.native"), $withheld] as $search) {
        $page->clear('#rg-admin-languages-search');
        $page->typeSlowly('#rg-admin-languages-search', $search, 40);
        languagesSearched($page, $search);

        expect(languagesScreen($page))->toMatchArray(['rows' => [$withheld], 'count' => '1 of '.count(supportedLocales()).' installed'])
            ->and($page->script('new URLSearchParams(location.search).get("q")'))->toBe($search);
    }

    // A request that is not sent has no event to wait for. Livewire debounces
    // a live wire:model by 150 ms, so twice that is long enough for one to go out.
    proveNothingHappensFor($page, 0.3, 'a request to Livewire');
    expect(livewireUpdatesSinceWatching($page))->toBe(['fetches' => 0, 'requests' => 0, 'sameDocument' => true]);

    // The watch does see Livewire: a tab is a request.
    $page->click('nav[aria-label="Language status"] a[href*="status=enabled"]');

    eventually(fn () => expect(livewireUpdatesSinceWatching($page)['fetches'])->toBeGreaterThan(0)
        ->and(livewireUpdatesSinceWatching($page)['requests'])->toBeGreaterThan(0));
});

it('clears the search back to every row, and the URL with it, without asking the server', function () {
    $page = visitLanguages('/admin/languages?q=klingon');
    watchLivewireUpdates($page);

    expect(languagesScreen($page))->toMatchArray(['rows' => [], 'noMatch' => true, 'count' => '0 of '.count(supportedLocales()).' installed']);
    $page->assertSee('No installed language matches “klingon”');

    // A request that is not sent has no event to wait for. Livewire debounces
    // a live wire:model by 150 ms, so twice that is long enough for one to go out.
    proveNothingHappensFor($page->click('Clear search'), 0.3, 'a request to Livewire');

    eventually(fn () => expect(languagesScreen($page))->toMatchArray(['rows' => supportedLocales(), 'query' => '', 'noMatch' => false, 'count' => count(supportedLocales()).' of '.count(supportedLocales()).' installed'])
        ->and($page->script('location.search'))->toBe('')
        ->and(languagesFocused($page))->toBe('rg-admin-languages-search')
        ->and(livewireUpdatesSinceWatching($page))->toBe(['fetches' => 0, 'requests' => 0, 'sameDocument' => true]));
});

it('clears a typed search from the button in the field, which is there only while there is text', function () {
    [, $withheld] = twoTranslatedLocales();
    $clearShown = '(() => { const clear = document.querySelector("#rg-admin-languages-search ~ .rg-admin-search__clear"); return getComputedStyle(clear).display !== "none" && clear.getBoundingClientRect().width > 0 })()';

    $page = visitLanguages('/admin/languages');
    watchLivewireUpdates($page);

    // An empty field has nothing to clear.
    expect($page->script($clearShown))->toBeFalse();

    $page->typeSlowly('#rg-admin-languages-search', $withheld, 40);
    waitForScript($page, $clearShown);

    // Inside the field's frame, after the text.
    expect($page->script(<<<'JS'
        (() => {
            const input = document.getElementById('rg-admin-languages-search')
            const frame = input.closest('.rg-admin-search').getBoundingClientRect()
            const field = input.getBoundingClientRect()
            const clear = input.closest('.rg-admin-search').querySelector('.rg-admin-search__clear').getBoundingClientRect()

            return clear.left >= field.right && clear.right <= frame.right && clear.top >= frame.top && clear.bottom <= frame.bottom
        })()
    JS))->toBeTrue()
        ->and(languagesScreen($page)['rows'])->toBe([$withheld]);

    $page->click('#rg-admin-languages-search ~ .rg-admin-search__clear');
    waitForScript($page, '[...document.querySelectorAll(\'[role="table"] [role="row"][id^="rg-admin-language-"]\')].filter((row) => getComputedStyle(row).display !== "none").length', count(supportedLocales()));

    expect(languagesScreen($page))->toMatchArray(['rows' => supportedLocales(), 'query' => '', 'noMatch' => false, 'count' => count(supportedLocales()).' of '.count(supportedLocales()).' installed'])
        ->and($page->script('location.search'))->toBe('')
        ->and(languagesFocused($page))->toBe('rg-admin-languages-search')
        ->and($page->script($clearShown))->toBeFalse()
        ->and(livewireUpdatesSinceWatching($page))->toBe(['fetches' => 0, 'requests' => 0, 'sameDocument' => true]);

    // Opened with a search, the field has text to clear straight away.
    $page = visitLanguages('/admin/languages?q='.rawurlencode($withheld));
    waitForScript($page, $clearShown);
});

it('keeps the search across tabs, in their links, the URL and Back', function () {
    [, $withheld] = twoTranslatedLocales();
    offerEveryInstalledLocaleExcept($withheld);
    $other = array_values(array_diff(translatedLocales(), [$withheld]))[0];
    $query = $withheld;

    $page = visitLanguages('/admin/languages');
    $page->typeSlowly('#rg-admin-languages-search', $query, 40);
    languagesSearched($page, $query);

    // Every tab link carries the search, as a link of its own.
    expect($page->script('[...document.querySelectorAll("nav[aria-label=\"Language status\"] a")].map((a) => new URL(a.href).searchParams.get("q"))'))
        ->toBe([$query, $query, $query, $query]);

    // The answer to a tab redraws its rows unfiltered; the browser's search then hides the rest.
    $page->click('nav[aria-label="Language status"] a[href*="status=disabled"]');
    languagesOnTab($page, 'disabled');

    eventually(fn () => expect(languagesScreen($page))->toMatchArray(['rows' => [$withheld], 'query' => $query, 'count' => '1 of '.count(supportedLocales()).' installed'])
        ->and($page->script('Object.fromEntries(new URLSearchParams(location.search))'))->toBe(['q' => $query, 'status' => 'disabled']));

    // On Enabled the same search finds nothing, and says so.
    $page->click('nav[aria-label="Language status"] a[href*="status=enabled"]');
    languagesOnTab($page, 'enabled');
    eventually(fn () => expect(languagesScreen($page))->toMatchArray(['rows' => [], 'noMatch' => true, 'query' => $query]));

    $page->back();
    languagesOnTab($page, 'disabled');
    eventually(fn () => expect(languagesScreen($page))->toMatchArray(['rows' => [$withheld], 'query' => $query])
        ->and($page->script('Object.fromEntries(new URLSearchParams(location.search))'))->toBe(['q' => $query, 'status' => 'disabled']));

    // A different search on another tab, then back to All: still the search, still in the links.
    $page->clear('#rg-admin-languages-search');
    $page->typeSlowly('#rg-admin-languages-search', $other, 40);
    languagesSearched($page, $other);
    expect(languagesScreen($page)['rows'])->toBe([]);

    $page->click('nav[aria-label="Language status"] a:not([href*="status="])');
    languagesOnTab($page, 'all');
    eventually(fn () => expect(languagesScreen($page))->toMatchArray(['rows' => [$other], 'query' => $other])
        ->and($page->script('new URLSearchParams(location.search).get("status")'))->toBeNull());
});

it('searches thirty-five languages in the page', function () {
    $codes = installLanguagesUpTo(35);
    $made = $codes[20];

    $page = visitLanguages('/admin/languages');
    watchLivewireUpdates($page);

    expect(languagesScreen($page))->toMatchArray(['rows' => $codes, 'count' => '35 of 35 installed']);

    $page->typeSlowly('#rg-admin-languages-search', $made, 40);
    languagesSearched($page, $made);
    expect(languagesScreen($page))->toMatchArray(['rows' => [$made], 'count' => '1 of 35 installed']);

    // "Language X…" is in every made-up name: a search can match many at once.
    $page->clear('#rg-admin-languages-search');
    $page->typeSlowly('#rg-admin-languages-search', 'language x', 40);
    languagesSearched($page, 'language x');
    expect(languagesScreen($page)['rows'])->toBe(array_values(array_filter($codes, fn (string $code): bool => str_starts_with($code, 'x'))));

    // A request that is not sent has no event to wait for. Livewire debounces
    // a live wire:model by 150 ms, so twice that is long enough for one to go out.
    proveNothingHappensFor($page->clear('#rg-admin-languages-search'), 0.3, 'a request to Livewire');
    eventually(fn () => expect(languagesScreen($page)['rows'])->toBe($codes)
        ->and(livewireUpdatesSinceWatching($page))->toBe(['fetches' => 0, 'requests' => 0, 'sameDocument' => true]));
});

it('opens Translation Center from a missing item on that item, and from Translate all missing on everything missing', function () {
    [$target] = twoTranslatedLocales();
    $category = untranslatedCategory();
    $unit = "categories:{$category->id}:name";

    $page = visitLanguages('/admin/languages');
    $page->click("#rg-admin-language-{$target} .rg-admin-languages__missing");
    languagesOverlayOpen($page, 'drawer');

    $translate = $page->script("[...document.querySelectorAll('.rg-admin-languages__item')].find((item) => item.innerText.includes('Georgian food')).querySelector('a:not(.rg-admin-languages__edit-source)').getAttribute('href')");
    // Followed by the page itself: the plugin's navigate() retries a load that
    // takes longer than a second, and the retry interrupts the load it retries.
    $page->script('() => { location.href = '.json_encode($translate).'; return true; }');

    // Translation Center scrolls to the item and focuses it once its filters have drawn the row.
    eventually(fn () => expect($page->script('location.pathname + location.search'))->toBe("/admin/translation-center?locale={$target}&section=categories&mode=missing")
        ->and($page->script("document.activeElement?.closest('[data-unit]')?.dataset.unit"))->toBe($unit)
        ->and($page->script("document.querySelector('.rg-admin-translation-row--linked')?.dataset.unit"))->toBe($unit));

    $languages = visitLanguages('/admin/languages');
    $languages->click("#rg-admin-language-{$target} .rg-admin-languages__missing");
    languagesOverlayOpen($languages, 'drawer');
    $languages->click('.rg-admin-drawer__footer a');

    // A page load: Translation Center shows its rows once its own Alpine has applied the filters.
    eventually(fn () => expect($languages->script('location.pathname + location.search'))->toBe("/admin/translation-center?locale={$target}&mode=missing")
        ->and($languages->script("[...document.querySelectorAll('[data-unit]')].filter((row) => getComputedStyle(row).display !== 'none').map((row) => row.dataset.unit)"))->toContain($unit));
});
