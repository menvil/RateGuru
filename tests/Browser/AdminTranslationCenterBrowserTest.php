<?php

use App\Actions\Translations\DiscardProjectTranslationGenerationAction;
use App\Models\Category;
use App\Models\ProjectSettings;
use App\Models\Tag;
use App\Models\User;
use App\Support\TranslationEngine\Enums\TranslationErrorCode;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\actingAs;

/**
 * Translation Center in a real browser: the Admin v2 layout at each width, the
 * target language combobox, the filters that never ask the server, the drafts
 * that live only in the browser until Save, the context drawer, and the
 * question before a draft is lost — measured from the page the browser built.
 */

/** What the screen shows right now. */
function translationScreen(mixed $page): array
{
    return $page->script(<<<'JS'
        (() => {
            const visible = (el) => !! el && getComputedStyle(el).display !== 'none' && el.getBoundingClientRect().width > 0
            // Two strips are drawn, plain and with sparkles; at most one shows.
            const strip = [...document.querySelectorAll('.rg-admin-notice--strip')].find(visible)

            return {
                rows: [...document.querySelectorAll('[role="table"] [role="row"][data-unit]')].filter((row) => visible(row)).map((row) => row.dataset.unit),
                count: document.querySelector('.rg-admin-toolbar__count')?.textContent.trim() ?? null,
                stats: [...document.querySelectorAll('.rg-admin-stats .rg-admin-stat')].map((stat) => stat.innerText.replace(/\s+/g, ' ').trim()),
                strip: strip ? strip.innerText.replace(/\s+/g, ' ').trim() : null,
                stripKind: strip?.closest('[data-strip]')?.dataset.strip ?? null,
                search: location.search,
                dialog: visible(document.querySelector('.rg-admin-dialog')),
                drawer: visible(document.querySelector('.rg-admin-drawer')),
                focused: document.activeElement?.id || document.activeElement?.innerText?.trim() || null,
                overflow: document.documentElement.scrollWidth > window.innerWidth,
                toasts: [...document.querySelectorAll('.rg-admin-toast-stack .rg-admin-toast__text')].map((toast) => toast.textContent.trim()),
            }
        })()
    JS);
}

/** One row as the browser shows it: its state badge, note, field and error. */
function translationRowState(mixed $page, string $unit): array
{
    return $page->script(sprintf(<<<'JS'
        (() => {
            const row = document.querySelector('[data-unit="%s"]')
            const visible = (el) => !! el && getComputedStyle(el).display !== 'none'
            const field = row.querySelector('input, textarea')

            return {
                state: [...row.querySelectorAll('.rg-admin-translation-row__state .rg-admin-badge')].filter(visible).map((badge) => badge.textContent.trim()),
                note: row.querySelector('.rg-admin-translation-row__note').textContent.trim(),
                value: field.value,
                changed: (field.closest('.rg-admin-input') ?? field).className.includes('changed'),
                invalid: field.getAttribute('aria-invalid'),
                describedBy: field.getAttribute('aria-describedby'),
                error: visible(row.querySelector('.rg-admin-error')) ? row.querySelector('.rg-admin-error').textContent.trim() : null,
                counter: row.querySelector('.rg-admin-translation-row__counter').textContent.trim(),
                canSave: ! [...row.querySelectorAll('button')].find((button) => button.textContent.trim().startsWith('Save') && ! button.textContent.includes('next')).disabled,
                discard: visible([...row.querySelectorAll('button')].find((button) => button.textContent.trim().startsWith('Discard'))),
            }
        })()
    JS, $unit));
}

/**
 * Opens Translation Center at one size once the screen has started: Alpine has
 * drawn it and lifted x-cloak, and what init() leaves for the next tick — the
 * filters written back to the URL, a link to one item followed — has run.
 */
function visitTranslationCenter(string $url, int $width, int $height): mixed
{
    $page = visit($url)->resize($width, $height);

    waitForScript($page, <<<'JS'
        (async () => {
            if (! document.querySelector('.rg-admin-translation-center')?._x_dataStack) {
                return false
            }

            // A tick asked for now runs after the one init() asked for.
            await Alpine.nextTick()

            return document.querySelector('[x-cloak]') === null && document.fonts.status === 'loaded'
        })()
    JS);

    return $page;
}

/** Types into one row's field. */
function typeTranslation(mixed $page, string $unit, string $text): void
{
    $selector = "[data-unit=\"{$unit}\"] input, [data-unit=\"{$unit}\"] textarea";
    $page->click($selector)->typeSlowly($selector, $text, 10);

    waitForTranslationDraft($page, $unit, $text);
}

/** Waits for a row to hold what was typed as its draft: the text in the field, the row marked Edited · not saved. */
function waitForTranslationDraft(mixed $page, string $unit, string $text): void
{
    waitForScript($page, sprintf(<<<'JS'
        (() => {
            const row = document.querySelector('[data-unit="%s"]')
            const edited = [...row.querySelectorAll('.rg-admin-translation-row__state .rg-admin-badge')].find((badge) => badge.textContent.trim() === 'Edited · not saved')

            return row.querySelector('input, textarea').value.includes(%s) && getComputedStyle(edited).display !== 'none'
        })()
    JS, $unit, json_encode($text)));
}

/** Opens the target language list with a click. */
function openTranslationTargetList(mixed $page): void
{
    $page->click('#rg-admin-translation-target-trigger');

    waitForTranslationTargetList($page, open: true);
}

/**
 * Waits for the target language list to be open — drawn, focus in its search —
 * or closed — hidden, focus back on its trigger. Alpine moves the focus and
 * shows or hides the list at different moments, the list on the next frame.
 */
function waitForTranslationTargetList(mixed $page, bool $open): void
{
    waitForScript($page, sprintf(
        "document.activeElement?.id === %s && (getComputedStyle(document.getElementById('rg-admin-translation-target-popover')).display === 'none') === %s",
        json_encode($open ? 'rg-admin-translation-target-search' : 'rg-admin-translation-target-trigger'),
        $open ? 'false' : 'true',
    ));
}

/**
 * Waits for the screen to be drawn afresh on another target language: the
 * server has answered, the URL names the language, and the new screen has
 * started and handed focus back to where the language was chosen.
 */
function waitForTranslationTarget(mixed $page, string $code): void
{
    waitForScript($page, sprintf(<<<'JS'
        (() => {
            const root = document.querySelector('.rg-admin-translation-center')

            return !! root?._x_dataStack
                && Alpine.$data(root).locale === %1$s
                && new URLSearchParams(location.search).get('locale') === %1$s
                && ! ('rgAdminTranslationCenterRefocus' in window)
        })()
    JS, json_encode($code)));
}

/** Clicks one of a row's buttons by its visible label. */
function clickRowButton(mixed $page, string $unit, string $label): void
{
    $page->script(sprintf(
        "[...document.querySelectorAll('[data-unit=\"%s\"] button')].find((button) => button.firstChild.textContent.trim() === %s || button.innerText.trim().startsWith(%s)).click()",
        $unit,
        json_encode($label),
        json_encode($label),
    ));
}

beforeEach(function () {
    ProjectSettings::factory()->create();
    actingAs(User::factory()->admin()->create());
    [$this->target, $this->other] = twoTranslatedLocales();
    offerLocales([$this->target]);
    $this->dogs = Category::factory()->create(['slug' => 'dogs', 'name' => 'Dogs', 'name_translations' => null, 'is_active' => true]);
    $this->cats = Category::factory()->create(['slug' => 'cats', 'name' => 'Cats', 'name_translations' => null, 'is_active' => true]);
    $this->birds = Category::factory()->create(['slug' => 'birds', 'name' => 'Birds', 'name_translations' => [$this->target => 'Птицы', $this->other => 'Птици'], 'is_active' => true]);
    $this->tag = Tag::factory()->create(['slug' => 'zoomies', 'name' => 'zoomies', 'name_translations' => null]);
    $this->unit = fn (Category|Tag $record): string => ($record instanceof Tag ? 'tags' : 'categories').":{$record->id}:name";
});

afterEach(fn () => removeCatalogScratchDirectory($this));

// The screen -------------------------------------------------------------------------

it('draws Translation Center in Admin v2, inside the shell, in the Localization section after Languages', function () {
    $page = visitTranslationCenter('/admin/translation-center', 1440, 900);

    expect($page->script(<<<'JS'
        (() => ({
            active: document.querySelector('#rg-admin-sidebar a[aria-current="page"] .rg-admin-nav-item__label')?.textContent.trim(),
            crumbs: [...document.querySelectorAll('.rg-admin-breadcrumb__item')].map((item) => item.textContent.trim()),
            filament: document.querySelectorAll('.fi-ta, .fi-ta-ctn, .fi-section, .fi-header-heading, .fi-modal').length,
            band: Math.round(document.querySelector('.rg-admin-translation-center__header').getBoundingClientRect().width),
            column: Math.round(document.querySelector('.fi-main-ctn').getBoundingClientRect().width),
            columns: getComputedStyle(document.querySelector('.rg-admin-translation-row:not(.rg-admin-translation-row--head)')).gridTemplateColumns.split(' ').length,
            itemWidth: Math.round(document.querySelector('.rg-admin-translation-row__item').getBoundingClientRect().width),
            trigger: Math.round(document.getElementById('rg-admin-translation-target-trigger').getBoundingClientRect().height),
            segment: Math.round(document.querySelector('.rg-admin-segmented__option').getBoundingClientRect().height),
            completion: getComputedStyle(document.querySelector('.rg-admin-translation-center__completion-bar')).height,
        }))()
    JS))->toMatchArray([
        'active' => 'Translation Center',
        'crumbs' => ['Localization', 'Translation Center'],
        'filament' => 0,
        'band' => 1140,
        'column' => 1140,
        'columns' => 3,
        'itemWidth' => 240,
        'trigger' => 52,
        'segment' => 32,
        'completion' => '8px',
    ]);

    $labels = $page->script("[...document.querySelectorAll('#rg-admin-sidebar .rg-admin-nav-item__label')].map((label) => label.textContent.trim())");

    expect(array_search('Translation Center', $labels))->toBe(array_search('Languages', $labels) + 1)
        ->and(translationScreen($page))->toMatchArray(['overflow' => false])
        ->and(translationScreen($page)['stats'][0])->toStartWith('TOTAL ITEMS');

    $page->assertNoJavaScriptErrors();
});

it('keeps Item, English and the target in that order at every width, without the page scrolling sideways', function (int $width, int $columns) {
    $page = visitTranslationCenter('/admin/translation-center', $width, 900);

    expect($page->script(<<<'JS'
        (() => {
            const row = document.querySelector('.rg-admin-translation-row:not(.rg-admin-translation-row--head)')
            const cells = [...row.querySelectorAll(':scope > [role="cell"]')].map((cell) => cell.getBoundingClientRect())

            return {
                columns: getComputedStyle(row).gridTemplateColumns.split(' ').length,
                order: cells[0].top <= cells[1].top && cells[1].top <= cells[2].top && cells[0].left <= cells[1].left + 1,
                overflow: document.documentElement.scrollWidth > window.innerWidth,
            }
        })()
    JS))->toBe(['columns' => $columns, 'order' => true, 'overflow' => false]);

    // The combobox opens inside the screen, never wider than it.
    openTranslationTargetList($page);

    expect($page->script("(() => { const box = document.querySelector('.rg-admin-combobox__popover').getBoundingClientRect(); return box.right <= window.innerWidth && box.left >= 0 && document.documentElement.scrollWidth <= window.innerWidth })()"))->toBeTrue();
})->with([
    'wide' => [1440, 3],
    'laptop' => [1280, 3],
    'rail' => [1024, 3],
    'phone' => [390, 1],
]);

it('lines the target field up with the English text, with its count, state and actions under it in that order', function () {
    $birds = ($this->unit)($this->birds);
    $page = visitTranslationCenter('/admin/translation-center', 1440, 900);

    expect($page->script(sprintf(<<<'JS'
        (() => {
            const row = document.querySelector('[data-unit="%s"]')
            const box = (selector) => row.querySelector(selector).getBoundingClientRect()
            const counter = box('.rg-admin-translation-row__counter')
            const badge = [...row.querySelectorAll('.rg-admin-translation-row__state .rg-admin-badge')].find((badge) => getComputedStyle(badge).display !== 'none').getBoundingClientRect()
            const save = box('.rg-admin-translation-row__actions button:not([style*="display: none"])')

            return {
                level: Math.abs(box('.rg-admin-translation-row__source').top - box('.rg-admin-translation-row__field-control').top) < 1,
                under: counter.top >= box('.rg-admin-translation-row__field-control').bottom,
                countFirst: counter.right < badge.left && Math.abs((counter.top + counter.bottom) / 2 - (badge.top + badge.bottom) / 2) < 4,
                actionsAfter: save.top >= badge.top - 1 && (save.top > badge.bottom || save.left > badge.right),
                state: row.querySelector('.rg-admin-translation-row__state').innerText.replace(/\s+/g, ' ').trim(),
            }
        })()
    JS, $birds)))->toBe(['level' => true, 'under' => true, 'countFirst' => true, 'actionsAfter' => true, 'state' => 'Saved Stored translation']);
});

it('keeps a short note beside its badge, and wraps a long one under the badge, never under the count or the actions', function (int $width, bool $oneLine) {
    [$dogs, $birds] = [($this->unit)($this->dogs), ($this->unit)($this->birds)];
    useScriptedTranslationProvider(ScriptedTranslationProvider::translating());
    $page = visitTranslationCenter('/admin/translation-center', $width, 900);
    suggestTranslationIn($page, $dogs, "[{$this->target}] Dogs");
    $layout = <<<'JS'
        (() => {
            const row = document.querySelector('[data-unit="%s"]')
            const counter = row.querySelector('.rg-admin-translation-row__counter').getBoundingClientRect()
            const badge = [...row.querySelectorAll('.rg-admin-translation-row__state .rg-admin-badge')].find((badge) => getComputedStyle(badge).display !== 'none').getBoundingClientRect()
            const note = row.querySelector('.rg-admin-translation-row__note').getBoundingClientRect()
            const actions = [...row.querySelectorAll('.rg-admin-translation-row__actions button')].filter((button) => getComputedStyle(button).display !== 'none').map((button) => button.getBoundingClientRect())

            return {
                beside: note.left > badge.right && note.top < badge.bottom,
                underBadge: note.top >= badge.bottom && Math.abs(note.left - badge.left) < 1 && note.left > counter.right,
                actionsOnBadgeLine: actions.every((button) => button.top < badge.bottom && button.bottom > badge.top),
            }
        })()
    JS;

    // “Stored translation” fits beside Saved; “Generated 09:41 · AI suggestions are drafts until saved.” does not,
    // and on a wide screen it goes under the badge while the actions keep their place on the badge's line.
    expect($page->script(sprintf($layout, $birds)))->toMatchArray(['beside' => true, 'underBadge' => false])
        ->and($page->script(sprintf($layout, $dogs)))->toMatchArray(['beside' => false, 'underBadge' => true, 'actionsOnBadgeLine' => $oneLine]);
})->with([
    'wide' => [2560, true],
    'laptop' => [1440, false],
    'rail' => [1024, false],
]);

it('keeps the header figures, completion included, on one line on a phone', function () {
    $page = visitTranslationCenter('/admin/translation-center', 390, 844);

    expect($page->script(<<<'JS'
        (() => {
            const stats = [...document.querySelectorAll('.rg-admin-stats .rg-admin-stat')].map((stat) => stat.getBoundingClientRect())
            const bar = document.querySelector('.rg-admin-translation-center__completion-bar').getBoundingClientRect()

            return {
                count: stats.length,
                oneLine: stats.every((stat) => Math.abs(stat.top - stats[0].top) < 1),
                inside: stats.every((stat) => stat.right <= window.innerWidth),
                bar: bar.width > 0 && bar.height === 8,
                overflow: document.documentElement.scrollWidth > window.innerWidth,
            }
        })()
    JS))->toBe(['count' => 4, 'oneLine' => true, 'inside' => true, 'bar' => true, 'overflow' => false]);
});

it('clears the search with its ×, shown only while there is text, without asking the server', function () {
    $page = visitTranslationCenter('/admin/translation-center', 1440, 900);
    $total = count(translationScreen($page)['rows']);
    $clear = "document.querySelector('.rg-admin-toolbar .rg-admin-search__clear')";
    watchLivewireUpdates($page);

    expect($page->script("getComputedStyle({$clear}).display"))->toBe('none');

    $page->typeSlowly('#rg-admin-translation-search', 'Dogs', 30);

    eventually(fn () => expect($page->script("getComputedStyle({$clear}).display"))->not->toBe('none')
        ->and($page->script("{$clear}.getAttribute('aria-label')"))->toBe('Clear search')
        ->and(translationScreen($page)['rows'])->toHaveCount(1));

    $page->click('.rg-admin-toolbar .rg-admin-search__clear');

    eventually(fn () => expect(translationScreen($page))->toMatchArray(['search' => "?locale={$this->target}", 'focused' => 'rg-admin-translation-search'])
        ->and(translationScreen($page)['rows'])->toHaveCount($total)
        ->and($page->script("getComputedStyle({$clear}).display"))->toBe('none'));

    // A request that is not sent has no event to wait for: this is long enough for a
    // field bound to the server to have sent one, past Livewire's 150 ms debounce.
    proveNothingHappensFor($page, 0.3, 'a request to Livewire');

    expect(livewireUpdatesSinceWatching($page))->toBe(['fetches' => 0, 'requests' => 0, 'sameDocument' => true]);
});

// The target language ----------------------------------------------------------------

it('chooses the target language from the keyboard, searching thirty-five languages in the page', function () {
    $codes = installLanguagesUpTo(35);
    $last = end($codes);
    $page = visitTranslationCenter('/admin/translation-center', 1440, 900);
    watchLivewireUpdates($page);

    $page->keys('#rg-admin-translation-target-trigger', 'Enter');
    waitForTranslationTargetList($page, open: true);

    expect(translationScreen($page)['focused'])->toBe('rg-admin-translation-target-search')
        ->and($page->script("document.querySelectorAll('#rg-admin-translation-target-listbox [role=option]').length"))->toBe(34)
        ->and($page->script("[...document.querySelectorAll('#rg-admin-translation-target-listbox [role=option]')].some((option) => option.dataset.value === 'en')"))->toBeFalse()
        ->and($page->script("getComputedStyle(document.getElementById('rg-admin-translation-target-listbox')).maxHeight"))->toBe('340px');

    // Typing filters the list in the browser.
    $page->typeSlowly('#rg-admin-translation-target-search', strtoupper($last), 30);
    waitForScript($page, "[...document.querySelectorAll('#rg-admin-translation-target-listbox [role=option]')].filter((option) => option.style.display !== 'none').length", 1);

    // A request that is not sent has no event to wait for: this is long enough for a
    // field bound to the server to have sent one, past Livewire's 150 ms debounce.
    proveNothingHappensFor($page, 0.3, 'a request to Livewire');

    expect($page->script("[...document.querySelectorAll('#rg-admin-translation-target-listbox [role=option]')].filter((option) => option.style.display !== 'none').map((option) => option.dataset.value)"))->toBe([$last])
        ->and(livewireUpdatesSinceWatching($page))->toBe(['fetches' => 0, 'requests' => 0, 'sameDocument' => true]);

    // Opened again on the chosen language, Up and Down move the active option and
    // Enter chooses it — here a disabled language.
    $page->keys('#rg-admin-translation-target-search', 'Escape');
    waitForTranslationTargetList($page, open: false);
    $page->keys('#rg-admin-translation-target-trigger', 'ArrowDown');
    waitForTranslationTargetList($page, open: true);

    // Each key moves the active option before its press returns.
    $page->keys('#rg-admin-translation-target-search', 'ArrowDown');
    $page->keys('#rg-admin-translation-target-search', 'ArrowDown');
    $page->keys('#rg-admin-translation-target-search', 'ArrowUp');
    $page->keys('#rg-admin-translation-target-search', 'ArrowDown');

    $active = $page->script("document.getElementById(document.getElementById('rg-admin-translation-target-search').getAttribute('aria-activedescendant')).dataset.value");

    // English first in config, then the target the page opened on.
    expect($codes[1])->toBe($this->target)
        ->and($active)->toBe($codes[3]);

    $page->keys('#rg-admin-translation-target-search', 'Enter');
    waitForTranslationTarget($page, $codes[3]);

    expect(translationScreen($page))->toMatchArray(['search' => "?locale={$codes[3]}", 'focused' => 'rg-admin-translation-target-trigger'])
        ->and($page->script("document.getElementById('rg-admin-translation-target-trigger').innerText"))->toContain('Disabled')
        ->and(livewireUpdatesSinceWatching($page)['fetches'])->toBeGreaterThan(0);

    // Escape closes the list back to its trigger.
    $page->keys('#rg-admin-translation-target-trigger', 'ArrowDown');
    waitForTranslationTargetList($page, open: true);
    $page->keys(':focus', 'Escape');
    waitForTranslationTargetList($page, open: false);

    expect(translationScreen($page)['focused'])->toBe('rg-admin-translation-target-trigger')
        ->and($page->script("getComputedStyle(document.getElementById('rg-admin-translation-target-popover')).display"))->toBe('none');

    $page->assertNoJavaScriptErrors();
});

it('asks before switching languages with unsaved drafts, keeps them on Keep editing, and drops only them on Discard and switch', function () {
    $dogs = ($this->unit)($this->dogs);
    $page = visitTranslationCenter('/admin/translation-center', 1440, 900);

    typeTranslation($page, $dogs, 'Собаки');
    openTranslationTargetList($page);
    $page->click("[role=option][data-value=\"{$this->other}\"]");
    waitForScript($page, 'document.activeElement?.innerText.trim()', 'Keep editing');

    expect(translationScreen($page))->toMatchArray(['dialog' => true, 'focused' => 'Keep editing', 'search' => "?locale={$this->target}"])
        ->and($page->script("document.querySelector('.rg-admin-dialog').innerText"))
        ->toContain('Discard unsaved translations?')
        ->toContain('1 '.config("locales.supported.{$this->target}.label").' edit is not saved.')
        ->toContain('Discard and switch');

    // Keep editing changes nothing, so nothing is left to wait out once the dialog has
    // handed focus back: a switch started by mistake drops the draft read below at once,
    // before the server answers.
    $page->click('.rg-admin-dialog .rg-admin-dialog__footer button:first-of-type');
    waitForScript($page, 'document.activeElement?.id', 'rg-admin-translation-target-trigger');

    expect(translationScreen($page))->toMatchArray(['dialog' => false, 'search' => "?locale={$this->target}", 'focused' => 'rg-admin-translation-target-trigger'])
        ->and(translationRowState($page, $dogs)['value'])->toBe('Собаки');

    openTranslationTargetList($page);
    $page->click("[role=option][data-value=\"{$this->other}\"]");
    waitForScript($page, 'document.activeElement?.innerText.trim()', 'Keep editing');
    $page->click('.rg-admin-dialog .rg-admin-button--primary');
    waitForTranslationTarget($page, $this->other);

    expect(translationScreen($page))->toMatchArray(['dialog' => false, 'search' => "?locale={$this->other}", 'strip' => null])
        ->and(translationRowState($page, $dogs))->toMatchArray(['value' => '', 'state' => ['Missing']])
        ->and($this->dogs->fresh()->name_translations)->toBeNull();

    // Without drafts it simply switches.
    openTranslationTargetList($page);
    $page->click("[role=option][data-value=\"{$this->target}\"]");
    waitForTranslationTarget($page, $this->target);

    expect(translationScreen($page))->toMatchArray(['dialog' => false, 'search' => "?locale={$this->target}"]);

    $page->assertNoJavaScriptErrors();
});

it('stays usable on its language when switching fails, and switches once the server answers again', function () {
    $dogs = ($this->unit)($this->dogs);
    $page = visitTranslationCenter('/admin/translation-center', 1440, 900);

    // The next Livewire request fails as a dropped connection would.
    $page->script(<<<'JS'
        (() => {
            const uri = document.querySelector('[data-update-uri]').getAttribute('data-update-uri')
            const fetch = window.fetch

            window.failLivewire = true
            window.fetch = function (input, ...rest) {
                if (window.failLivewire && String(input?.url ?? input).startsWith(uri)) {
                    return Promise.reject(new TypeError('Failed to fetch'))
                }

                return fetch.call(this, input, ...rest)
            }
        })()
    JS);

    openTranslationTargetList($page);
    $page->click("[role=option][data-value=\"{$this->other}\"]");

    // The failed request is told in a toast, the last thing the screen does about it.
    waitForScript($page, "document.querySelectorAll('.rg-admin-toast-stack .rg-admin-toast__text').length > 0");

    expect($page->script(<<<'JS'
        (() => {
            const root = document.querySelector('.rg-admin-translation-center')

            return {
                busy: root.getAttribute('aria-busy'),
                switching: Alpine.$data(root).switching,
                refocus: 'rgAdminTranslationCenterRefocus' in window,
                pointerEvents: getComputedStyle(document.querySelector('.rg-admin-translation-center__card')).pointerEvents,
                locale: Alpine.$data(root).locale,
            }
        })()
    JS))->toBe(['busy' => 'false', 'switching' => false, 'refocus' => false, 'pointerEvents' => 'auto', 'locale' => $this->target])
        ->and(translationScreen($page))->toMatchArray(['search' => "?locale={$this->target}"])
        ->and(translationScreen($page)['toasts'])->toContain('The language was not switched: the server did not answer. Try again.')
        ->and($page->script("document.getElementById('rg-admin-translation-target-trigger').innerText"))->toContain(config("locales.supported.{$this->target}.native"));

    // The screen still takes input…
    typeTranslation($page, $dogs, 'Собаки');
    expect(translationRowState($page, $dogs))->toMatchArray(['state' => ['Edited · not saved'], 'canSave' => true]);
    clickRowButton($page, $dogs, 'Discard');
    eventually(fn () => expect(translationRowState($page, $dogs)['state'])->toBe(['Missing']));

    // …and switches once the server answers again.
    $page->script('window.failLivewire = false');
    openTranslationTargetList($page);
    $page->click("[role=option][data-value=\"{$this->other}\"]");
    waitForTranslationTarget($page, $this->other);

    expect(translationScreen($page))->toMatchArray(['search' => "?locale={$this->other}", 'focused' => 'rg-admin-translation-target-trigger'])
        ->and($page->script("document.querySelector('.rg-admin-translation-center').getAttribute('aria-busy')"))->toBe('false');
});

// Filters ----------------------------------------------------------------------------

it('searches the English text, the keys and the translations, and filters by section and Missing only, without a single request to Livewire', function () {
    [$dogs, $cats, $birds, $tag] = [($this->unit)($this->dogs), ($this->unit)($this->cats), ($this->unit)($this->birds), ($this->unit)($this->tag)];
    $page = visitTranslationCenter('/admin/translation-center', 1440, 900);
    $total = count(translationScreen($page)['rows']);
    watchLivewireUpdates($page);

    // The English text.
    $page->typeSlowly('#rg-admin-translation-search', 'Dogs', 30);
    eventually(fn () => expect(translationScreen($page))->toMatchArray(['rows' => [$dogs], 'count' => "1 of {$total} items", 'search' => "?locale={$this->target}&q=Dogs"]));

    // A business key.
    $page->clear('#rg-admin-translation-search')->typeSlowly('#rg-admin-translation-search', 'categories.cats', 30);
    eventually(fn () => expect(translationScreen($page)['rows'])->toBe([$cats]));

    // A stored translation.
    $page->clear('#rg-admin-translation-search')->typeSlowly('#rg-admin-translation-search', 'Птицы', 30);
    eventually(fn () => expect(translationScreen($page)['rows'])->toBe([$birds]));

    // Section, then Missing only.
    $page->clear('#rg-admin-translation-search');
    waitForScript($page, 'location.search', "?locale={$this->target}");
    $page->click('#rg-admin-translation-section');
    waitForScript($page, "getComputedStyle(document.getElementById('rg-admin-translation-section-menu')).display !== 'none'");
    $page->click('[role=menuitemradio]:has-text("Categories")');
    eventually(fn () => expect(translationScreen($page))->toMatchArray(['rows' => [$dogs, $cats, $birds], 'search' => "?locale={$this->target}&section=categories"])
        ->and(translationScreen($page)['focused'])->toBe('rg-admin-translation-section'));

    $page->click('.rg-admin-segmented [role=radio]:first-child');
    eventually(fn () => expect(translationScreen($page))->toMatchArray(['rows' => [$dogs, $cats], 'count' => "2 of {$total} items", 'search' => "?locale={$this->target}&section=categories&mode=missing"]));

    // A request that is not sent has no event to wait for: this is long enough for a
    // field bound to the server to have sent one, past Livewire's 150 ms debounce.
    proveNothingHappensFor($page, 0.3, 'a request to Livewire');

    // The header counts the language, whatever the filters show.
    expect(translationScreen($page)['stats'][0])->toContain((string) $total)
        ->and(livewireUpdatesSinceWatching($page))->toBe(['fetches' => 0, 'requests' => 0, 'sameDocument' => true])
        ->and(in_array($tag, translationScreen($page)['rows'], true))->toBeFalse();

    $page->assertNoJavaScriptErrors();
});

it('opens with the filters in the URL, drops the ones it does not know, and clears them', function () {
    $page = visitTranslationCenter("/admin/translation-center?locale={$this->target}&section=nowhere&mode=everything&q=zzzz", 1440, 900);

    expect(translationScreen($page))->toMatchArray(['rows' => [], 'search' => "?locale={$this->target}&q=zzzz"])
        ->and($page->script("document.querySelector('.rg-admin-empty-state')?.innerText"))->toContain('No items match these filters');

    $page->click('Clear filters');

    eventually(fn () => expect(translationScreen($page))->toMatchArray(['search' => "?locale={$this->target}", 'focused' => 'rg-admin-translation-search'])
        ->and(count(translationScreen($page)['rows']))->toBeGreaterThan(3));
});

it('says when nothing is missing in Missing only, rather than that nothing matches', function () {
    ProjectSettings::query()->update(['static_pages' => json_encode(staticPagesTranslatedInto([$this->target]))]);
    $page = visitTranslationCenter("/admin/translation-center?locale={$this->target}&section=static_pages&mode=missing", 1440, 900);

    expect(translationScreen($page)['rows'])->toBe([])
        ->and($page->script("document.querySelector('.rg-admin-empty-state')?.innerText"))->toContain('Nothing missing here');
});

// Drafts -----------------------------------------------------------------------------

it('keeps a draft in the browser only: an edited state, a strip, Discard and Discard all, and nothing stored', function () {
    [$dogs, $cats] = [($this->unit)($this->dogs), ($this->unit)($this->cats)];
    $page = visitTranslationCenter('/admin/translation-center', 1440, 900);
    watchLivewireUpdates($page);

    expect(translationRowState($page, $dogs))->toMatchArray(['state' => ['Missing'], 'note' => 'Visitors see the English text', 'changed' => false, 'canSave' => false, 'discard' => false]);

    typeTranslation($page, $dogs, 'Собаки');
    typeTranslation($page, $cats, 'Кошки');

    // A request or a write that does not happen has no event to wait for: this is long
    // enough for a field bound to the server to have sent one, past Livewire's 150 ms debounce.
    proveNothingHappensFor($page, 0.3, 'a request to Livewire or a stored draft');

    expect(translationRowState($page, $dogs))->toMatchArray(['state' => ['Edited · not saved'], 'changed' => true, 'canSave' => true, 'discard' => true, 'counter' => '6 / 80 characters'])
        ->and(translationScreen($page)['strip'])->toBe('2 edits not saved yet. Nothing changes for visitors until you save. Discard all')
        ->and(livewireUpdatesSinceWatching($page))->toBe(['fetches' => 0, 'requests' => 0, 'sameDocument' => true])
        ->and($this->dogs->fresh()->name_translations)->toBeNull();

    // A filter that hides a draft keeps it.
    $page->typeSlowly('#rg-admin-translation-search', 'zoomies', 30);
    eventually(fn () => expect(translationScreen($page)['rows'])->toBe([($this->unit)($this->tag)])
        ->and(translationScreen($page)['strip'])->toStartWith('2 edits not saved yet.'));
    $page->clear('#rg-admin-translation-search');
    waitForScript($page, 'location.search', "?locale={$this->target}");
    expect(translationRowState($page, $dogs)['value'])->toBe('Собаки');

    // Discard goes back to what is stored, here nothing.
    clickRowButton($page, $dogs, 'Discard');
    eventually(fn () => expect(translationRowState($page, $dogs))->toMatchArray(['value' => '', 'state' => ['Missing']])
        ->and(translationScreen($page)['focused'])->toBe(translationFieldId($page, $dogs))
        ->and(translationScreen($page)['strip'])->toStartWith('1 edit not saved yet.'));

    // Discard all, back to the search.
    $page->click('[data-strip="edits"] .rg-admin-notice--strip button');
    eventually(fn () => expect(translationRowState($page, $cats)['value'])->toBe('')
        ->and(translationScreen($page))->toMatchArray(['strip' => null, 'focused' => 'rg-admin-translation-search']));

    // A request that is not sent has no event to wait for: this is long enough for a
    // field bound to the server to have sent one, past Livewire's 150 ms debounce.
    proveNothingHappensFor($page, 0.3, 'a request to Livewire');

    expect(livewireUpdatesSinceWatching($page))->toBe(['fetches' => 0, 'requests' => 0, 'sameDocument' => true]);
});

it('discards an edit of a stored translation back to the stored text', function () {
    $birds = ($this->unit)($this->birds);
    $page = visitTranslationCenter('/admin/translation-center', 1440, 900);

    expect(translationRowState($page, $birds))->toMatchArray(['state' => ['Saved'], 'note' => 'Stored translation', 'value' => 'Птицы']);

    typeTranslation($page, $birds, ' и не только');

    expect(translationRowState($page, $birds))->toMatchArray(['state' => ['Edited · not saved'], 'note' => 'Saved version is kept until you save']);

    clickRowButton($page, $birds, 'Discard');

    eventually(fn () => expect(translationRowState($page, $birds))->toMatchArray(['state' => ['Saved'], 'value' => 'Птицы']));
});

it('warns before leaving the page with a draft, and opens again on what is stored', function () {
    $dogs = ($this->unit)($this->dogs);
    $page = visitTranslationCenter('/admin/translation-center', 1440, 900);
    $leaving = "(() => { const event = new Event('beforeunload', { cancelable: true }); window.dispatchEvent(event); return event.defaultPrevented })()";

    expect($page->script($leaving))->toBeFalse();

    typeTranslation($page, $dogs, 'Собаки');

    expect($page->script($leaving))->toBeTrue();

    $again = visitTranslationCenter('/admin/translation-center', 1440, 900);

    expect(translationRowState($again, $dogs))->toMatchArray(['value' => '', 'state' => ['Missing']]);
});

// Save -------------------------------------------------------------------------------

it('saves one row: stored, Saved, counted and announced, focus kept on the field', function () {
    $dogs = ($this->unit)($this->dogs);
    $page = visitTranslationCenter('/admin/translation-center', 1440, 900);
    $before = translationScreen($page)['stats'];

    typeTranslation($page, $dogs, 'Собаки');
    clickRowButton($page, $dogs, 'Save');

    // Stored once the row says so. What is waited on is the page, never the database: the
    // server runs in the test's own process and answers only while the test talks to the browser.
    eventually(fn () => expect(translationRowState($page, $dogs)['state'])->toBe(['Saved']));

    $after = translationScreen($page);

    expect($this->dogs->fresh()->name_translations)->toBe([$this->target => 'Собаки'])
        ->and(translationRowState($page, $dogs))->toMatchArray(['state' => ['Saved'], 'value' => 'Собаки', 'canSave' => false])
        ->and($after['toasts'])->toContain(config("locales.supported.{$this->target}.label").' translation saved')
        ->and($after['strip'])->toBeNull()
        ->and($after['focused'])->toBe(translationFieldId($page, $dogs))
        ->and($after['stats'][1])->not->toBe($before[1])
        ->and($after['stats'][2])->not->toBe($before[2]);
});

it('saves and moves on to the next item the filters show, which in Missing only is the next missing one', function () {
    [$dogs, $cats] = [($this->unit)($this->dogs), ($this->unit)($this->cats)];
    $page = visitTranslationCenter("/admin/translation-center?locale={$this->target}&section=categories&mode=missing", 1440, 900);

    expect(translationScreen($page)['rows'])->toBe([$dogs, $cats]);

    typeTranslation($page, $dogs, 'Собаки');
    clickRowButton($page, $dogs, 'Save & next');

    // Stored once the screen has moved on. What is waited on is the page, never the database: the
    // server runs in the test's own process and answers only while the test talks to the browser.
    eventually(fn () => expect(translationScreen($page))->toMatchArray(['rows' => [$cats], 'focused' => translationFieldId($page, $cats)]));

    expect(translationScreen($page))->toMatchArray(['rows' => [$cats], 'focused' => translationFieldId($page, $cats)])
        ->and($this->dogs->fresh()->name_translations)->toBe([$this->target => 'Собаки']);

    // The last one simply saves.
    $page->typeSlowly("[data-unit=\"{$cats}\"] input", 'Кошки', 10);
    waitForTranslationDraft($page, $cats, 'Кошки');
    clickRowButton($page, $cats, 'Save & next');
    eventually(fn () => expect(translationScreen($page)['rows'])->toBe([]));

    expect($this->cats->fresh()->name_translations)->toBe([$this->target => 'Кошки'])
        ->and(translationScreen($page)['rows'])->toBe([])
        ->and($page->script("document.querySelector('.rg-admin-empty-state')?.innerText"))->toContain('Nothing missing here');

    // Save moves focus on in Alpine's next tick; that runs before the page's errors are read.
    $page->script('Alpine.nextTick()');

    $page->assertNoJavaScriptErrors();
});

it('keeps an error next to the field, blocks Save and stores nothing', function () {
    $dogs = ($this->unit)($this->dogs);
    $page = visitTranslationCenter('/admin/translation-center', 1440, 900);

    typeTranslation($page, $dogs, str_repeat('я', 84));

    $state = translationRowState($page, $dogs);

    expect($state)->toMatchArray(['error' => '4 over the limit', 'invalid' => 'true', 'canSave' => false, 'counter' => '84 / 80 characters'])
        ->and($state['describedBy'])->toEndWith('-error');

    clickRowButton($page, $dogs, 'Save');

    // A write that does not happen has no event to wait for: this gives a save that
    // went out anyway the time of a round trip to the server to be stored.
    proveNothingHappensFor($page, 0.5, 'the blocked save being stored');

    expect($this->dogs->fresh()->name_translations)->toBeNull();
});

it('asks a translation to keep every placeholder of the English text', function () {
    $pages = ProjectSettings::findOrFail(1)->static_pages;
    $pages['contact']['en']['content'] = 'Write to {contact_email}.';
    ProjectSettings::query()->update(['static_pages' => json_encode($pages)]);
    $page = visitTranslationCenter("/admin/translation-center?locale={$this->target}&section=static_pages", 1440, 900);
    $field = '[data-unit="static_pages:contact:content"] textarea';

    $page->clear($field)->typeSlowly($field, 'Пишите нам.', 10);
    waitForTranslationDraft($page, 'static_pages:contact:content', 'Пишите нам.');

    expect(translationRowState($page, 'static_pages:contact:content'))->toMatchArray(['error' => 'Keep {contact_email}', 'invalid' => 'true', 'canSave' => false])
        ->and($page->script('document.querySelector(\'[data-unit="static_pages:contact:content"] .rg-admin-constraint-chip--placeholder\').textContent'))->toBe('{contact_email}');
});

// Context ----------------------------------------------------------------------------

it('opens the context of one item in the drawer, read only, and closes it back to its button', function () {
    $birds = ($this->unit)($this->birds);
    $page = visitTranslationCenter('/admin/translation-center', 1440, 900);
    $button = "[data-unit=\"{$birds}\"] .rg-admin-translation-row__context";

    // The drawer opens once the server has answered with the item's context, and takes focus.
    $page->click($button);
    waitForScript($page, "!! document.activeElement?.closest('.rg-admin-drawer')");

    $drawer = (string) $page->script("document.querySelector('.rg-admin-drawer').innerText");

    expect(translationScreen($page)['drawer'])->toBeTrue()
        ->and($drawer)
        ->toContain('Birds · Name')
        ->toContain('The category’s name on posts and in the upload form.')
        ->toContain('categories.birds.name')
        ->toContain('80 characters')
        ->toContain('Single line')
        ->toContain('Птици')
        ->toContain('Edit source')
        ->and($page->script("document.querySelector('.rg-admin-drawer__footer a').getAttribute('href')"))->toBe(route('filament.admin.resources.categories.edit', ['record' => $this->birds]))
        ->and($page->script("Math.round(document.querySelector('.rg-admin-drawer').getBoundingClientRect().width)"))->toBe(448)
        ->and($page->script("!! document.activeElement.closest('.rg-admin-drawer')"))->toBeTrue();

    foreach (range(1, 6) as $press) {
        $page->keys(':focus', 'Tab');
    }

    expect($page->script("!! document.activeElement.closest('.rg-admin-drawer')"))->toBeTrue();

    // Focus comes back to the button and the drawer hides at different moments, the drawer on the next frame.
    $page->keys(':focus', 'Escape');

    eventually(fn () => expect(translationScreen($page)['drawer'])->toBeFalse()
        ->and($page->script('document.activeElement === document.querySelector('.json_encode($button).')'))->toBeTrue());

    // The screen forgets the drawer in Alpine's next tick; that runs before the page's errors are read.
    $page->script('Alpine.nextTick()');

    $page->assertNoJavaScriptErrors();
});

it('fits the drawer to a phone', function () {
    $page = visitTranslationCenter('/admin/translation-center', 390, 844);
    $page->click('[data-unit="'.($this->unit)($this->dogs).'"] .rg-admin-translation-row__context');
    waitForScript($page, "!! document.activeElement?.closest('.rg-admin-drawer')");

    expect($page->script("Math.round(document.querySelector('.rg-admin-drawer').getBoundingClientRect().width)"))->toBe(390)
        ->and(translationScreen($page)['overflow'])->toBeFalse();
});

// AI suggestions ---------------------------------------------------------------------

/** One row's AI side as the browser shows it. */
function translationAiState(mixed $page, string $unit): array
{
    return $page->script(sprintf(<<<'JS'
        (() => {
            const row = document.querySelector('[data-unit="%s"]')
            const visible = (el) => !! el && getComputedStyle(el).display !== 'none'
            const field = row.querySelector('input, textarea')
            const frame = field.closest('.rg-admin-input') ?? field
            const target = row.querySelector('.rg-admin-translation-row__target')
            const buttons = [...row.querySelectorAll('button')]
            const ai = buttons.find((button) => button.querySelector('.rg-admin-icon') && ['AI translate', 'Suggest alternative', 'Regenerate'].includes(button.querySelector('span')?.textContent.trim()))
            const save = buttons.find((button) => button.innerText.trim().startsWith('Save') && ! button.innerText.includes('next'))
            // What the info tokens resolve to here, to compare the field and cell against.
            const probe = (token) => {
                const swatch = row.appendChild(document.createElement('span'))
                swatch.style.background = `var(${token})`
                const colour = getComputedStyle(swatch).backgroundColor
                swatch.remove()

                return colour
            }

            return {
                state: [...row.querySelectorAll('.rg-admin-translation-row__state .rg-admin-badge')].filter(visible).map((badge) => badge.textContent.trim()),
                note: visible(row.querySelector('.rg-admin-translation-row__note')) ? row.querySelector('.rg-admin-translation-row__note').textContent.trim() : null,
                generating: visible(row.querySelector('.rg-admin-translation-row__generating')),
                value: field.value,
                readonly: field.readOnly,
                busy: target.getAttribute('aria-busy'),
                infoField: getComputedStyle(frame).backgroundColor === probe('--rg-admin-status-info-field') && frame.className.includes('--info'),
                infoCell: getComputedStyle(target).backgroundColor === probe('--rg-admin-status-info-cell'),
                ai: visible(ai) ? ai.querySelector('span').textContent.trim() : null,
                aiDisabled: ai?.getAttribute('aria-disabled') ?? null,
                canSave: ! save.disabled,
                describedBy: field.getAttribute('aria-describedby'),
            }
        })()
    JS, $unit));
}

/** What the page's status region says aloud. */
function translationAnnouncement(mixed $page): string
{
    return (string) $page->script("document.querySelector('.rg-admin-translation-center span.rg-admin-sr-only[role=\"status\"]').textContent.trim()");
}

/**
 * Puts the page's Livewire requests behind a gate: held until released, or
 * failed as a dropped connection would fail them.
 */
function gateLivewireRequests(mixed $page): void
{
    $page->script(<<<'JS'
        (() => {
            const uri = document.querySelector('[data-update-uri]').getAttribute('data-update-uri')
            const fetch = window.fetch
            const gate = window.livewireGate = { hold: false, fail: false, waiting: [] }

            window.fetch = function (input, ...rest) {
                if (String(input?.url ?? input).startsWith(uri)) {
                    if (gate.fail) {
                        return Promise.reject(new TypeError('Failed to fetch'))
                    }

                    if (gate.hold) {
                        return new Promise((resolve, reject) => gate.waiting.push(() => fetch.call(this, input, ...rest).then(resolve, reject)))
                    }
                }

                return fetch.call(this, input, ...rest)
            }

            window.releaseLivewire = () => {
                gate.hold = false
                gate.waiting.splice(0).forEach((go) => go())
            }
        })()
    JS);
}

/**
 * Waits for one row to show that its suggestion is on its way: busy, and the
 * indicator drawn — Alpine applies the two a tick apart.
 */
function waitForGenerating(mixed $page, string $unit): void
{
    waitForScript($page, sprintf(
        "(() => { const row = document.querySelector('[data-unit=\"%s\"]'); return row.querySelector('.rg-admin-translation-row__target').getAttribute('aria-busy') === 'true' && getComputedStyle(row.querySelector('.rg-admin-translation-row__generating')).display !== 'none' })()",
        $unit,
    ));
}

/** Asks for an AI suggestion on one row, from its focused button, and waits for the row to take it. */
function suggestTranslationIn(mixed $page, string $unit, string $expected): void
{
    focusAiButton($page, $unit)->script('document.activeElement.click()');

    eventually(fn () => expect(translationAiState($page, $unit))->toMatchArray(['state' => ['AI suggestion · not saved'], 'value' => $expected]));
}

/** Moves focus to one row's AI translate or Regenerate button, as a keyboard would. */
function focusAiButton(mixed $page, string $unit): mixed
{
    $page->script(sprintf(
        "[...document.querySelectorAll('[data-unit=\"%s\"] button')].find((button) => ['AI translate', 'Suggest alternative', 'Regenerate'].includes(button.querySelector('span')?.textContent.trim())).focus()",
        $unit,
    ));

    return $page;
}

it('fills a missing row with an AI suggestion that stays a draft until Save, counted only once saved', function () {
    $dogs = ($this->unit)($this->dogs);
    useScriptedTranslationProvider(answeringTranslationProvider(['Собаки']));
    $page = visitTranslationCenter('/admin/translation-center', 1440, 900);
    $before = translationScreen($page)['stats'];
    gateLivewireRequests($page);

    expect(translationAiState($page, $dogs))->toMatchArray(['state' => ['Missing'], 'ai' => 'AI translate', 'aiDisabled' => 'false', 'canSave' => false]);

    // The row waits on its own: read-only, busy, its actions paused, and said aloud.
    $page->script('window.livewireGate.hold = true');
    focusAiButton($page, $dogs)->script('document.activeElement.click()');
    waitForGenerating($page, $dogs);

    expect(translationAiState($page, $dogs))->toMatchArray([
        'state' => ['Missing'], 'generating' => true, 'readonly' => true, 'busy' => 'true', 'aiDisabled' => 'true', 'canSave' => false, 'note' => null,
    ])->and(translationAnnouncement($page))->toStartWith('Generating a ')
        ->and(translationRowState($page, ($this->unit)($this->cats))['value'])->toBe('');

    $page->script('window.releaseLivewire()');
    eventually(fn () => expect(translationAiState($page, $dogs)['state'])->toBe(['AI suggestion · not saved']));

    $suggested = translationAiState($page, $dogs);
    $screen = translationScreen($page);

    expect($suggested)->toMatchArray([
        'value' => 'Собаки', 'generating' => false, 'readonly' => false, 'busy' => 'false', 'infoField' => true, 'infoCell' => true,
        'ai' => 'Regenerate', 'aiDisabled' => 'false', 'canSave' => true,
    ])->and($suggested['note'])->toMatch('/^Generated \d\d:\d\d · AI suggestions are drafts until saved\.$/')
        ->and($suggested['describedBy'])->toContain('-note')
        ->and($screen['focused'])->toBe(translationFieldId($page, $dogs))
        ->and($screen['stats'])->toBe($before)
        ->and($screen['strip'])->toBe('1 AI suggestion not saved yet. Nothing changes for visitors until you save. Discard all')
        ->and($screen['stripKind'])->toBe('ai')
        ->and(translationAnnouncement($page))->toContain('AI suggestion ready')
        ->and($this->dogs->fresh()->name_translations)->toBeNull();

    // Save is the ordinary save, and only then is it counted.
    clickRowButton($page, $dogs, 'Save');
    eventually(fn () => expect(translationAiState($page, $dogs)['state'])->toBe(['Saved']));

    $after = translationScreen($page);

    expect($this->dogs->fresh()->name_translations)->toBe([$this->target => 'Собаки'])
        ->and(translationAiState($page, $dogs))->toMatchArray(['infoField' => false, 'infoCell' => false, 'ai' => 'Suggest alternative', 'canSave' => false])
        ->and($after['strip'])->toBeNull()
        ->and($after['stats'][1])->not->toBe($before[1])
        ->and($after['stats'][2])->not->toBe($before[2]);

    $page->assertNoJavaScriptErrors();
});

it('replaces an AI suggestion with another on Regenerate, and keeps it when Regenerate fails', function () {
    $dogs = ($this->unit)($this->dogs);
    useScriptedTranslationProvider(answeringTranslationProvider(['Собаки', 'Псы', TranslationErrorCode::ProviderUnavailable]));
    $page = visitTranslationCenter('/admin/translation-center', 1440, 900);
    gateLivewireRequests($page);

    suggestTranslationIn($page, $dogs, 'Собаки');

    // While the next one is on its way the first stays in the field, read-only.
    $page->script('window.livewireGate.hold = true');
    focusAiButton($page, $dogs)->script('document.activeElement.click()');
    waitForGenerating($page, $dogs);

    expect(translationAiState($page, $dogs))->toMatchArray(['value' => 'Собаки', 'readonly' => true, 'generating' => true, 'state' => ['AI suggestion · not saved']]);

    $page->script('window.releaseLivewire()');
    eventually(fn () => expect(translationAiState($page, $dogs))->toMatchArray(['value' => 'Псы', 'generating' => false, 'state' => ['AI suggestion · not saved']]));

    expect($this->dogs->fresh()->name_translations)->toBeNull();

    // A Regenerate that fails says so and changes nothing: the suggestion stays, focus stays on the button.
    focusAiButton($page, $dogs)->script('document.activeElement.click()');
    waitForScript($page, "document.querySelectorAll('.rg-admin-toast-stack .rg-admin-toast__text').length > 0");

    expect(translationScreen($page)['toasts'])->toContain('The translation provider is unavailable. Try again.')
        ->and(translationAiState($page, $dogs))->toMatchArray([
            'value' => 'Псы', 'state' => ['AI suggestion · not saved'], 'generating' => false, 'readonly' => false, 'busy' => 'false', 'ai' => 'Regenerate', 'aiDisabled' => 'false',
        ])
        ->and($page->script('document.activeElement.querySelector("span")?.textContent.trim()'))->toBe('Regenerate')
        ->and($this->dogs->fresh()->name_translations)->toBeNull();
});

it('suggests an alternative to a saved translation, keeping the saved one until the alternative is saved', function () {
    $birds = ($this->unit)($this->birds);
    useScriptedTranslationProvider(answeringTranslationProvider(['Пернатые', 'Птахи']));
    $page = visitTranslationCenter('/admin/translation-center', 1440, 900);
    $before = translationScreen($page)['stats'];

    expect(translationAiState($page, $birds))->toMatchArray(['state' => ['Saved'], 'value' => 'Птицы', 'ai' => 'Suggest alternative', 'aiDisabled' => 'false']);

    suggestTranslationIn($page, $birds, 'Пернатые');

    $suggested = translationAiState($page, $birds);

    expect($suggested)->toMatchArray(['infoField' => true, 'infoCell' => true, 'ai' => 'Regenerate', 'canSave' => true])
        ->and($suggested['note'])->toMatch('/^Generated \d\d:\d\d · Saved version is kept until you save\.$/')
        ->and(translationScreen($page)['stats'])->toBe($before)
        ->and($this->birds->fresh()->name_translations[$this->target])->toBe('Птицы');

    // Discard goes back to the saved translation, which never left.
    clickRowButton($page, $birds, 'Discard');
    eventually(fn () => expect(translationAiState($page, $birds))->toMatchArray(['state' => ['Saved'], 'value' => 'Птицы', 'ai' => 'Suggest alternative']));

    // Saving an alternative replaces the saved translation; the figures stay, nothing went missing.
    suggestTranslationIn($page, $birds, 'Птахи');
    clickRowButton($page, $birds, 'Save');
    eventually(fn () => expect(translationAiState($page, $birds))->toMatchArray(['state' => ['Saved'], 'value' => 'Птахи']));

    expect($this->birds->fresh()->name_translations[$this->target])->toBe('Птахи')
        ->and(translationScreen($page)['stats'])->toBe($before);
});

it('says so when the alternative is the saved translation word for word, and leaves the row as it was', function () {
    $birds = ($this->unit)($this->birds);
    useScriptedTranslationProvider(answeringTranslationProvider(['Птицы']));
    $page = visitTranslationCenter('/admin/translation-center', 1440, 900);

    focusAiButton($page, $birds)->script('document.activeElement.click()');
    waitForScript($page, "document.querySelectorAll('.rg-admin-toast-stack .rg-admin-toast__text').length > 0");

    expect(translationScreen($page)['toasts'])->toContain('AI suggested the same text as the saved translation.')
        ->and(translationAiState($page, $birds))->toMatchArray(['state' => ['Saved'], 'value' => 'Птицы', 'generating' => false, 'ai' => 'Suggest alternative']);
});

it('refuses an alternative once someone else has changed the saved translation, and says to reload', function () {
    $birds = ($this->unit)($this->birds);
    useScriptedTranslationProvider(answeringTranslationProvider(['Пернатые']));
    $page = visitTranslationCenter('/admin/translation-center', 1440, 900);

    $this->birds->update(['name_translations' => [$this->target => 'Изменено в другой вкладке', $this->other => 'Птици']]);

    focusAiButton($page, $birds)->script('document.activeElement.click()');
    waitForScript($page, "document.querySelectorAll('.rg-admin-toast-stack .rg-admin-toast__text').length > 0");

    expect(translationScreen($page)['toasts'])->toContain('This translation was changed by someone else. Reload the page to review it.')
        ->and(translationAiState($page, $birds))->toMatchArray(['state' => ['Saved'], 'value' => 'Птицы', 'generating' => false]);
});

it('turns an AI suggestion that is edited into an ordinary edit, saved as typed', function () {
    $dogs = ($this->unit)($this->dogs);
    useScriptedTranslationProvider(answeringTranslationProvider(['Собаки']));
    $page = visitTranslationCenter('/admin/translation-center', 1440, 900);

    suggestTranslationIn($page, $dogs, 'Собаки');
    typeTranslation($page, $dogs, ' и щенки');

    expect(translationAiState($page, $dogs))->toMatchArray([
        'state' => ['Edited · not saved'], 'value' => 'Собаки и щенки', 'infoField' => false, 'infoCell' => false, 'ai' => null, 'canSave' => true,
    ])->and(translationScreen($page))->toMatchArray(['strip' => '1 edit not saved yet. Nothing changes for visitors until you save. Discard all', 'stripKind' => 'edits']);

    clickRowButton($page, $dogs, 'Save');
    eventually(fn () => expect(translationAiState($page, $dogs)['state'])->toBe(['Saved']));

    expect($this->dogs->fresh()->name_translations)->toBe([$this->target => 'Собаки и щенки']);
});

it('discards an AI suggestion back to Missing, storing nothing', function () {
    $dogs = ($this->unit)($this->dogs);
    useScriptedTranslationProvider(answeringTranslationProvider(['Собаки']));
    $page = visitTranslationCenter('/admin/translation-center', 1440, 900);

    suggestTranslationIn($page, $dogs, 'Собаки');
    clickRowButton($page, $dogs, 'Discard');

    eventually(fn () => expect(translationAiState($page, $dogs))->toMatchArray([
        'state' => ['Missing'], 'value' => '', 'note' => 'Visitors see the English text', 'infoField' => false, 'infoCell' => false, 'ai' => 'AI translate',
    ])->and(translationScreen($page))->toMatchArray(['strip' => null, 'focused' => translationFieldId($page, $dogs)]));

    expect($this->dogs->fresh()->name_translations)->toBeNull();
});

it('keeps AI suggestions and edits side by side through the filters, and discards them all together', function () {
    [$dogs, $cats, $tag] = [($this->unit)($this->dogs), ($this->unit)($this->cats), ($this->unit)($this->tag)];
    useScriptedTranslationProvider(answeringTranslationProvider(['Предложение ИИ']));
    $page = visitTranslationCenter('/admin/translation-center', 1440, 900);
    watchLivewireUpdates($page);

    suggestTranslationIn($page, $dogs, 'Предложение ИИ');
    typeTranslation($page, $cats, 'Кошки');
    suggestTranslationIn($page, $tag, 'Предложение ИИ');

    expect(translationScreen($page))->toMatchArray([
        'strip' => '2 AI suggestions and 1 edit not saved yet. Nothing changes for visitors until you save. Discard all',
        'stripKind' => 'ai',
    ])->and(livewireUpdatesSinceWatching($page)['fetches'])->toBe(2);

    // Filters run in the page and keep every draft, AI or not; an unsaved suggestion is still missing.
    $page->typeSlowly('#rg-admin-translation-search', 'zoomies', 30);
    eventually(fn () => expect(translationScreen($page)['rows'])->toBe([$tag]));
    $page->clear('#rg-admin-translation-search');
    $page->script("Alpine.\$data(document.querySelector('.rg-admin-translation-center')).mode = 'missing'");
    eventually(fn () => expect(translationScreen($page)['rows'])->toContain($dogs)->toContain($cats)->toContain($tag));

    expect(translationAiState($page, $dogs))->toMatchArray(['state' => ['AI suggestion · not saved'], 'value' => 'Предложение ИИ'])
        ->and(translationAiState($page, $cats))->toMatchArray(['state' => ['Edited · not saved'], 'value' => 'Кошки'])
        ->and(livewireUpdatesSinceWatching($page)['fetches'])->toBe(2);

    $page->script("[...document.querySelectorAll('.rg-admin-notice--strip')].find((strip) => getComputedStyle(strip.parentElement).display !== 'none').querySelector('button').click()");

    eventually(fn () => expect(array_map(fn (string $unit): string => translationAiState($page, $unit)['value'], [$dogs, $cats, $tag]))->toBe(['', '', ''])
        ->and(translationScreen($page)['strip'])->toBeNull());

    expect($this->dogs->fresh()->name_translations)->toBeNull()
        ->and($this->cats->fresh()->name_translations)->toBeNull()
        ->and($this->tag->fresh()->name_translations)->toBeNull();
});

it('asks before an AI suggestion is dropped by switching language, and before leaving the page', function () {
    $dogs = ($this->unit)($this->dogs);
    useScriptedTranslationProvider(answeringTranslationProvider(['Собаки']));
    $page = visitTranslationCenter('/admin/translation-center', 1440, 900);
    $leaving = "(() => { const event = new Event('beforeunload', { cancelable: true }); window.dispatchEvent(event); return event.defaultPrevented })()";

    suggestTranslationIn($page, $dogs, 'Собаки');

    expect($page->script($leaving))->toBeTrue();

    openTranslationTargetList($page);
    $page->click("[role=option][data-value=\"{$this->other}\"]");
    waitForScript($page, 'document.activeElement?.innerText.trim()', 'Keep editing');

    expect($page->script("document.querySelector('.rg-admin-dialog').innerText"))
        ->toContain('Discard unsaved translations?')
        ->toContain('1 '.config("locales.supported.{$this->target}.label").' AI suggestion is not saved.');

    $page->click('.rg-admin-dialog .rg-admin-dialog__footer button:first-of-type');
    waitForScript($page, 'document.activeElement?.id', 'rg-admin-translation-target-trigger');

    expect(translationAiState($page, $dogs))->toMatchArray(['state' => ['AI suggestion · not saved'], 'value' => 'Собаки']);

    openTranslationTargetList($page);
    $page->click("[role=option][data-value=\"{$this->other}\"]");
    waitForScript($page, 'document.activeElement?.innerText.trim()', 'Keep editing');
    $page->click('.rg-admin-dialog .rg-admin-button--primary');
    waitForTranslationTarget($page, $this->other);

    expect(translationAiState($page, $dogs))->toMatchArray(['state' => ['Missing'], 'value' => '', 'ai' => 'AI translate', 'busy' => 'false'])
        ->and(translationScreen($page))->toMatchArray(['strip' => null, 'dialog' => false])
        ->and($this->dogs->fresh()->name_translations)->toBeNull();
});

it('recovers from a suggestion request that fails, leaving manual translation as it was', function () {
    $dogs = ($this->unit)($this->dogs);
    useScriptedTranslationProvider(answeringTranslationProvider(['Собаки']));
    $page = visitTranslationCenter('/admin/translation-center', 1440, 900);
    gateLivewireRequests($page);

    $page->script('window.livewireGate.fail = true');
    focusAiButton($page, $dogs)->script('document.activeElement.click()');
    waitForScript($page, "document.querySelectorAll('.rg-admin-toast-stack .rg-admin-toast__text').length > 0");

    expect(translationScreen($page)['toasts'])->toContain('No suggestion: the server did not answer. Try again.')
        ->and(translationAiState($page, $dogs))->toMatchArray([
            'state' => ['Missing'], 'value' => '', 'generating' => false, 'readonly' => false, 'busy' => 'false', 'ai' => 'AI translate', 'aiDisabled' => 'false',
        ])
        ->and(translationScreen($page)['strip'])->toBeNull();

    // Manual translation goes on as before, once the server answers again.
    $page->script('window.livewireGate.fail = false');
    typeTranslation($page, $dogs, 'Собаки');
    clickRowButton($page, $dogs, 'Save');
    eventually(fn () => expect(translationAiState($page, $dogs)['state'])->toBe(['Saved']));

    expect($this->dogs->fresh()->name_translations)->toBe([$this->target => 'Собаки']);
});

it('says safely when machine translation is not configured, and leaves the row usable', function () {
    $dogs = ($this->unit)($this->dogs);
    configureOpenAiTranslation(['api_key' => null]);
    $page = visitTranslationCenter('/admin/translation-center', 1440, 900);

    focusAiButton($page, $dogs)->script('document.activeElement.click()');
    waitForScript($page, "document.querySelectorAll('.rg-admin-toast-stack .rg-admin-toast__text').length > 0");

    expect(translationScreen($page)['toasts'])->toContain('Machine translation is not configured.')
        ->and(translationAiState($page, $dogs))->toMatchArray(['state' => ['Missing'], 'value' => '', 'readonly' => false, 'busy' => 'false', 'aiDisabled' => 'false']);
});

it('shows in the context drawer what AI translate sends, other languages as context only', function () {
    $dogs = ($this->unit)($this->dogs);
    configureOpenAiTranslation();
    $this->dogs->update(['name_translations' => [$this->other => 'Кучета']]);
    $page = visitTranslationCenter('/admin/translation-center', 1440, 900);

    $page->click("[data-unit=\"{$dogs}\"] .rg-admin-translation-row__context");
    waitForScript($page, "!! document.activeElement?.closest('.rg-admin-drawer')");

    $sends = (string) $page->script("document.getElementById('rg-admin-translation-context-ai').closest('section').innerText");
    $others = (string) $page->script("document.getElementById('rg-admin-translation-context-others').closest('section').innerText");
    $drawer = (string) $page->script("document.querySelector('.rg-admin-drawer').innerHTML");
    $target = config("locales.supported.{$this->target}");

    expect($page->script("document.getElementById('rg-admin-translation-context-ai').textContent.trim()"))->toBe('What AI translate sends')
        ->and($sends)
        ->toContain("{$target['label']} — {$target['native']} · {$this->target}")
        ->toContain('English · en · authoritative')
        ->toContain('Dogs')
        ->toContain('categories.name')
        ->toContain('Usage: The category’s name on posts and in the upload form.')
        ->toContain('80 characters')
        ->toContain('Single line')
        ->toContain('Кучета')
        ->toContain('context only')
        ->toContain('Its output is a suggestion until someone saves it.')
        ->and(substr_count($sends, " · {$this->target}"))->toBe(1)
        ->and($others)->toContain('AI context only')->toContain('English remains the authoritative source.')
        ->and($drawer)->not->toContain(TRANSLATION_TEST_API_KEY)->not->toContain('gpt-6-luna')->not->toContain('api.openai.com')
        ->and(translationScreen($page)['overflow'])->toBeFalse();
});

it('opens the context of one item, AI context included, with thirty-five languages installed', function () {
    $codes = installLanguagesUpTo(35);
    $translations = [];

    foreach (array_slice($codes, 2) as $code) {
        $translations[$code] = "Dogs in {$code}";
    }

    $this->dogs->update(['name_translations' => $translations]);
    $dogs = ($this->unit)($this->dogs);
    $page = visitTranslationCenter("/admin/translation-center?locale={$codes[1]}", 390, 844);
    watchLivewireUpdates($page);

    $page->click("[data-unit=\"{$dogs}\"] .rg-admin-translation-row__context");
    waitForScript($page, "!! document.activeElement?.closest('.rg-admin-drawer')");

    expect($page->script("document.querySelectorAll('.rg-admin-translation-context__payload-list li').length"))->toBe(33)
        ->and($page->script("Math.round(document.querySelector('.rg-admin-drawer').getBoundingClientRect().width)"))->toBe(390)
        ->and(translationScreen($page)['overflow'])->toBeFalse()
        ->and(livewireUpdatesSinceWatching($page)['fetches'])->toBe(1);
});

it('keeps an AI suggestion\'s actions on the screen at every width', function (int $width) {
    $dogs = ($this->unit)($this->dogs);
    useScriptedTranslationProvider(answeringTranslationProvider(['Собаки']));
    $page = visitTranslationCenter('/admin/translation-center', $width, 900);

    suggestTranslationIn($page, $dogs, 'Собаки');

    expect($page->script(sprintf(<<<'JS'
        (() => {
            const row = document.querySelector('[data-unit="%s"]')
            const shown = [...row.querySelectorAll('.rg-admin-translation-row__actions button')].filter((button) => getComputedStyle(button).display !== 'none')

            return {
                labels: shown.map((button) => button.innerText.trim().split(/\s/)[0]),
                inside: shown.every((button) => { const box = button.getBoundingClientRect(); return box.left >= 0 && box.right <= window.innerWidth }),
                overflow: document.documentElement.scrollWidth > window.innerWidth,
            }
        })()
    JS, $dogs)))->toBe(['labels' => ['Discard', 'Regenerate', 'Save', 'Save'], 'inside' => true, 'overflow' => false]);
})->with([1440, 1280, 1024, 768, 390]);

// Generate missing ---------------------------------------------------------------------

/** What the page shows of its background generation: the notice, and the two header actions. */
function generationScreen(mixed $page): array
{
    return $page->script(<<<'JS'
        (() => {
            const visible = (el) => !! el && getComputedStyle(el).display !== 'none' && el.getBoundingClientRect().width > 0
            const notice = document.querySelector('[data-strip="generation"]')
            const buttons = [...document.querySelectorAll('.rg-admin-translation-center__actions button')]
            const generate = buttons.find((button) => button.innerText.trim().startsWith('Generate missing'))
            const saveAll = buttons.find((button) => button.innerText.trim().startsWith('Save all generated'))

            return {
                notice: visible(notice) ? notice.innerText.replace(/\s+/g, ' ').trim() : null,
                generate: visible(generate) ? generate.innerText.trim() : null,
                generateDisabled: generate?.disabled ?? null,
                saveAll: visible(saveAll) ? saveAll.innerText.trim() : null,
                saveAllDisabled: saveAll?.disabled ?? null,
                busy: [...document.querySelectorAll('.rg-admin-translation-row__target[aria-busy="true"]')].map((cell) => cell.closest('[data-unit]').dataset.unit),
            }
        })()
    JS);
}

/** Clicks Generate missing and confirms the dialog it opens; waits for the progress notice. */
function generateMissingInPage(mixed $page): void
{
    $page->script("[...document.querySelectorAll('.rg-admin-translation-center__actions button')].find((button) => button.innerText.trim().startsWith('Generate missing')).click()");
    waitForScript($page, 'document.activeElement?.innerText.trim()', 'Cancel');
    $page->click('.rg-admin-dialog .rg-admin-button--primary');
    waitForScript($page, "(() => { const notice = document.querySelector('[data-strip=\"generation\"]'); return !! notice && getComputedStyle(notice).display !== 'none' })()");
}

/** Every unit the page lists as missing in the target language. */
function missingRowsInPage(mixed $page): array
{
    return $page->script("(() => { const data = Alpine.\$data(document.querySelector('.rg-admin-translation-center')); return data.order.filter((id) => data.units[id].stored === '') })()");
}

it('generates every missing translation in the background, as drafts that change nothing until saved', function () {
    Queue::fake();
    $dogs = ($this->unit)($this->dogs);
    $birds = ($this->unit)($this->birds);
    $provider = useScriptedTranslationProvider(ScriptedTranslationProvider::translating());
    $page = visitTranslationCenter('/admin/translation-center', 1440, 900);
    $before = translationScreen($page)['stats'];
    $missing = missingRowsInPage($page);

    expect(generationScreen($page))->toMatchArray(['generate' => 'Generate missing ('.count($missing).')', 'generateDisabled' => false, 'saveAll' => null, 'notice' => null]);

    generateMissingInPage($page);

    // Queued, not yet generated: the missing rows wait, everything else stays usable.
    expect(generationScreen($page)['notice'])->toContain('Generating 0 of '.count($missing).' translations…')
        ->toContain('You can leave this page. Generation will continue in the background.')
        ->and(generationScreen($page))->toMatchArray(['generate' => null])
        ->and(generationScreen($page)['busy'])->toBe($missing)
        ->and(translationAiState($page, $dogs))->toMatchArray(['readonly' => true, 'generating' => true, 'canSave' => false])
        ->and(translationAiState($page, $birds))->toMatchArray(['readonly' => false, 'ai' => 'Suggest alternative'])
        ->and($provider->received)->toBe([])
        ->and($this->dogs->fresh()->name_translations)->toBeNull();

    runTranslationGenerationJobs();

    eventually(fn () => expect(translationAiState($page, $dogs))->toMatchArray(['state' => ['AI suggestion · not saved'], 'value' => "[{$this->target}] Dogs"]), 8);

    $screen = generationScreen($page);

    expect($screen['notice'])->toContain(count($missing).' AI suggestions ready')->toContain('Generated drafts are temporary.')
        ->and($screen)->toMatchArray(['saveAll' => 'Save all generated ('.count($missing).')', 'saveAllDisabled' => false, 'generate' => null, 'busy' => []])
        ->and(translationAiState($page, $dogs))->toMatchArray(['readonly' => false, 'infoField' => true, 'canSave' => true])
        ->and(translationScreen($page)['stats'])->toBe($before)
        ->and($this->dogs->fresh()->name_translations)->toBeNull();

    $page->assertNoJavaScriptErrors();
});

it('brings the suggestions back after a reload and on a new visit, and warns about none of them when leaving', function () {
    Queue::fake();
    $dogs = ($this->unit)($this->dogs);
    useScriptedTranslationProvider(ScriptedTranslationProvider::translating());
    $page = visitTranslationCenter('/admin/translation-center', 1440, 900);
    $leaving = "(() => { const event = new Event('beforeunload', { cancelable: true }); window.dispatchEvent(event); return event.defaultPrevented })()";

    generateMissingInPage($page);

    // Leaving while it runs costs nothing: the work is the server's.
    expect($page->script($leaving))->toBeFalse();

    $again = visitTranslationCenter('/admin/translation-center', 1440, 900);

    expect(generationScreen($again)['notice'])->toContain('Generating 0 of')
        ->and(translationAiState($again, $dogs))->toMatchArray(['readonly' => true, 'generating' => true]);

    runTranslationGenerationJobs();
    eventually(fn () => expect(translationAiState($again, $dogs)['state'])->toBe(['AI suggestion · not saved']), 8);

    // Untouched suggestions are safe on the server: still no warning.
    expect($again->script($leaving))->toBeFalse();

    $later = visitTranslationCenter('/admin/translation-center', 1440, 900);

    expect(translationAiState($later, $dogs))->toMatchArray(['state' => ['AI suggestion · not saved'], 'value' => "[{$this->target}] Dogs"])
        ->and(generationScreen($later)['saveAll'])->toStartWith('Save all generated (');
});

it('switches language without asking over untouched suggestions, and asks once one is edited', function () {
    Queue::fake();
    [$dogs, $cats] = [($this->unit)($this->dogs), ($this->unit)($this->cats)];
    useScriptedTranslationProvider(ScriptedTranslationProvider::translating());
    $page = visitTranslationCenter('/admin/translation-center', 1440, 900);
    generateMissingInPage($page);
    runTranslationGenerationJobs();
    eventually(fn () => expect(translationAiState($page, $dogs)['state'])->toBe(['AI suggestion · not saved']), 8);

    openTranslationTargetList($page);
    $page->click("[role=option][data-value=\"{$this->other}\"]");
    waitForTranslationTarget($page, $this->other);

    expect(translationScreen($page)['dialog'])->toBeFalse()
        ->and(translationAiState($page, $dogs)['state'])->toBe(['Missing']);

    openTranslationTargetList($page);
    $page->click("[role=option][data-value=\"{$this->target}\"]");
    waitForTranslationTarget($page, $this->target);

    expect(translationAiState($page, $dogs))->toMatchArray(['state' => ['AI suggestion · not saved'], 'value' => "[{$this->target}] Dogs"]);

    // An edited suggestion is the page's own now: switching would lose it, so it asks.
    typeTranslation($page, $cats, '!');
    openTranslationTargetList($page);
    $page->click("[role=option][data-value=\"{$this->other}\"]");
    waitForScript($page, 'document.activeElement?.innerText.trim()', 'Keep editing');

    expect($page->script("document.querySelector('.rg-admin-dialog').innerText"))->toContain('1 '.config("locales.supported.{$this->target}.label").' edit is not saved.');
});

it('does not show a suggestion made from English that changed since, and does not save it', function () {
    Queue::fake();
    [$dogs, $cats] = [($this->unit)($this->dogs), ($this->unit)($this->cats)];
    useScriptedTranslationProvider(ScriptedTranslationProvider::translating());
    $page = visitTranslationCenter('/admin/translation-center', 1440, 900);
    generateMissingInPage($page);
    runTranslationGenerationJobs();
    eventually(fn () => expect(translationAiState($page, $dogs)['state'])->toBe(['AI suggestion · not saved']), 8);
    $ready = (int) preg_replace('/\D/', '', (string) generationScreen($page)['saveAll']);

    $this->dogs->update(['name' => 'Dog lovers']);
    $page = visitTranslationCenter('/admin/translation-center', 1440, 900);

    expect(translationAiState($page, $dogs))->toMatchArray(['state' => ['Missing'], 'value' => '', 'canSave' => false])
        ->and($page->script("document.querySelector('[data-unit=\"{$dogs}\"] .rg-admin-translation-row__bulk-issue').innerText.trim()"))
        ->toBe('English changed after this suggestion was generated. Generate a new translation.')
        ->and(generationScreen($page)['saveAll'])->toBe('Save all generated ('.($ready - 1).')')
        ->and(translationAiState($page, $cats)['state'])->toBe(['AI suggestion · not saved']);

    $page->script("[...document.querySelectorAll('.rg-admin-translation-center__actions button')].find((button) => button.innerText.trim().startsWith('Save all generated')).click()");
    waitForScript($page, 'document.activeElement?.innerText.trim()', 'Cancel');
    $page->click('.rg-admin-dialog .rg-admin-button--primary');
    eventually(fn () => expect(translationAiState($page, $cats)['state'])->toBe(['Saved']), 8);

    expect($this->dogs->fresh()->name_translations)->toBeNull()
        ->and($this->cats->fresh()->name_translations)->toBe([$this->target => "[{$this->target}] Cats"]);
});

it('saves the suggestions it has when some failed, leaving the failed rows missing and usable', function () {
    Queue::fake();
    [$dogs, $cats] = [($this->unit)($this->dogs), ($this->unit)($this->cats)];
    useScriptedTranslationProvider(new ScriptedTranslationProvider(
        fn ($request) => scriptedTranslationResponse($request, array_map(
            fn ($item) => ['id' => $item->id, 'text' => $item->sourceText === 'Cats' ? '' : "[{$request->targetLocale}] {$item->sourceText}"],
            $request->items,
        )),
    ));
    $page = visitTranslationCenter('/admin/translation-center', 1440, 900);
    generateMissingInPage($page);
    runTranslationGenerationJobs();
    eventually(fn () => expect(translationAiState($page, $dogs)['state'])->toBe(['AI suggestion · not saved']), 8);

    $missing = count(missingRowsInPage($page));

    expect(generationScreen($page)['notice'])->toContain(($missing - 1).' AI suggestions ready · 1 failed')
        ->and(generationScreen($page)['saveAll'])->toBe('Save all generated ('.($missing - 1).')')
        ->and(translationAiState($page, $cats))->toMatchArray(['state' => ['Missing'], 'readonly' => false, 'ai' => 'AI translate'])
        ->and(translationRowState($page, $cats)['invalid'])->not->toBe('true')
        ->and($page->script("document.querySelector('[data-unit=\"{$cats}\"] .rg-admin-translation-row__bulk-issue').innerText.trim()"))->toContain('did not meet this field’s constraints');

    // The failed row still translates by hand.
    typeTranslation($page, $cats, 'Кошки');
    expect(translationAiState($page, $cats)['canSave'])->toBeTrue();
});

it('makes an edited suggestion an ordinary edit, saved as typed and never brought back', function () {
    Queue::fake();
    $dogs = ($this->unit)($this->dogs);
    useScriptedTranslationProvider(ScriptedTranslationProvider::translating());
    $page = visitTranslationCenter('/admin/translation-center', 1440, 900);
    generateMissingInPage($page);
    runTranslationGenerationJobs();
    eventually(fn () => expect(translationAiState($page, $dogs)['state'])->toBe(['AI suggestion · not saved']), 8);

    typeTranslation($page, $dogs, ' (geprüft)');

    expect(translationAiState($page, $dogs))->toMatchArray(['state' => ['Edited · not saved'], 'infoField' => false, 'ai' => null]);

    clickRowButton($page, $dogs, 'Save');
    eventually(fn () => expect(translationAiState($page, $dogs)['state'])->toBe(['Saved']));

    expect($this->dogs->fresh()->name_translations)->toBe([$this->target => "[{$this->target}] Dogs (geprüft)"]);

    $again = visitTranslationCenter('/admin/translation-center', 1440, 900);

    expect(translationAiState($again, $dogs))->toMatchArray(['state' => ['Saved'], 'value' => "[{$this->target}] Dogs (geprüft)"]);
});

it('discards with Discard all only the drafts it counts, an edited suggestion on the server too, and leaves the untouched ones', function () {
    Queue::fake();
    [$dogs, $cats] = [($this->unit)($this->dogs), ($this->unit)($this->cats)];
    useScriptedTranslationProvider(ScriptedTranslationProvider::translating());
    $page = visitTranslationCenter('/admin/translation-center', 1440, 900);
    generateMissingInPage($page);
    runTranslationGenerationJobs();
    eventually(fn () => expect(translationAiState($page, $dogs)['state'])->toBe(['AI suggestion · not saved']), 8);
    $ready = count(missingRowsInPage($page));

    typeTranslation($page, $cats, ' (geprüft)');
    $edits = "(() => { const strip = document.querySelector('[data-strip=\"edits\"]'); return getComputedStyle(strip).display === 'none' ? null : strip.innerText.replace(/\\s+/g, ' ').trim() })()";

    expect($page->script($edits))->toBe('1 edit not saved yet. Nothing changes for visitors until you save. Discard all');

    $page->click('[data-strip="edits"] .rg-admin-notice--strip button');
    eventually(fn () => expect(translationAiState($page, $cats))->toMatchArray(['state' => ['Missing'], 'value' => '']));

    expect($page->script($edits))->toBeNull()
        ->and(translationAiState($page, $dogs))->toMatchArray(['state' => ['AI suggestion · not saved'], 'value' => "[{$this->target}] Dogs"])
        ->and(generationScreen($page)['notice'])->toContain(($ready - 1).' AI suggestions ready')->toContain('1 discarded')
        ->and(translationGenerationItem(translationGenerationOf(auth()->user(), $this->target), $cats)['status'])->toBe('discarded');

    // A reload brings back the untouched suggestions, and not the one let go.
    $again = visitTranslationCenter('/admin/translation-center', 1440, 900);

    expect(translationAiState($again, $dogs)['state'])->toBe(['AI suggestion · not saved'])
        ->and(translationAiState($again, $cats))->toMatchArray(['state' => ['Missing'], 'value' => '']);
});

it('discards one suggestion on the server, and saves one from the server\'s copy', function () {
    Queue::fake();
    [$dogs, $cats] = [($this->unit)($this->dogs), ($this->unit)($this->cats)];
    useScriptedTranslationProvider(ScriptedTranslationProvider::translating());
    $page = visitTranslationCenter('/admin/translation-center', 1440, 900);
    generateMissingInPage($page);
    runTranslationGenerationJobs();
    eventually(fn () => expect(translationAiState($page, $dogs)['state'])->toBe(['AI suggestion · not saved']), 8);

    clickRowButton($page, $dogs, 'Discard');
    eventually(fn () => expect(translationAiState($page, $dogs))->toMatchArray(['state' => ['Missing'], 'value' => '']));

    clickRowButton($page, $cats, 'Save');
    eventually(fn () => expect(translationAiState($page, $cats)['state'])->toBe(['Saved']));

    expect($this->cats->fresh()->name_translations)->toBe([$this->target => "[{$this->target}] Cats"])
        ->and($this->dogs->fresh()->name_translations)->toBeNull();

    $again = visitTranslationCenter('/admin/translation-center', 1440, 900);

    expect(translationAiState($again, $dogs))->toMatchArray(['state' => ['Missing'], 'value' => ''])
        ->and(translationAiState($again, $cats))->toMatchArray(['state' => ['Saved'], 'value' => "[{$this->target}] Cats"]);
});

it('lets a row go when its suggestion was already let go elsewhere, and discards nothing else', function () {
    Queue::fake();
    [$dogs, $cats] = [($this->unit)($this->dogs), ($this->unit)($this->cats)];
    useScriptedTranslationProvider(ScriptedTranslationProvider::translating());
    $page = visitTranslationCenter('/admin/translation-center', 1440, 900);
    generateMissingInPage($page);
    runTranslationGenerationJobs();
    eventually(fn () => expect(translationAiState($page, $dogs)['state'])->toBe(['AI suggestion · not saved']), 8);
    $generation = translationGenerationOf(auth()->user(), $this->target);

    // Another tab discarded it: this one still shows it.
    app(DiscardProjectTranslationGenerationAction::class)->handle(auth()->user(), $this->target, $generation['batch'], $dogs);

    clickRowButton($page, $dogs, 'Discard');
    eventually(fn () => expect(translationAiState($page, $dogs))->toMatchArray(['state' => ['Missing'], 'value' => '']));

    expect(translationScreen($page)['toasts'])->toBe([])
        ->and(translationAiState($page, $cats)['state'])->toBe(['AI suggestion · not saved'])
        ->and(translationGenerationItem(translationGenerationOf(auth()->user(), $this->target), $cats)['status'])->toBe('ready');
});

it('asks before saving all generated, saves nothing on Cancel and everything on confirm', function () {
    Queue::fake();
    $dogs = ($this->unit)($this->dogs);
    useScriptedTranslationProvider(ScriptedTranslationProvider::translating());
    $page = visitTranslationCenter('/admin/translation-center', 1440, 900);
    $before = translationScreen($page)['stats'];
    generateMissingInPage($page);
    runTranslationGenerationJobs();
    eventually(fn () => expect(translationAiState($page, $dogs)['state'])->toBe(['AI suggestion · not saved']), 8);
    $count = count(missingRowsInPage($page));

    $saveAll = "[...document.querySelectorAll('.rg-admin-translation-center__actions button')].find((button) => button.innerText.trim().startsWith('Save all generated')).click()";
    $page->script($saveAll);
    waitForScript($page, 'document.activeElement?.innerText.trim()', 'Cancel');

    expect($page->script("document.querySelector('.rg-admin-dialog').innerText"))
        ->toContain("Save {$count} generated translations?")
        ->toContain('This publishes these AI suggestions to visitors.')
        ->toContain("Save {$count} translations");

    $page->click('.rg-admin-dialog .rg-admin-dialog__footer button:first-of-type');
    eventually(fn () => expect(translationScreen($page)['dialog'])->toBeFalse());

    expect($this->dogs->fresh()->name_translations)->toBeNull()
        ->and(translationAiState($page, $dogs)['state'])->toBe(['AI suggestion · not saved']);

    $page->script($saveAll);
    waitForScript($page, 'document.activeElement?.innerText.trim()', 'Cancel');
    $page->click('.rg-admin-dialog .rg-admin-button--primary');
    eventually(fn () => expect(translationAiState($page, $dogs)['state'])->toBe(['Saved']), 8);
    eventually(fn () => expect(translationScreen($page)['toasts'])->toContain("{$count} translations saved"));

    expect($this->dogs->fresh()->name_translations)->toBe([$this->target => "[{$this->target}] Dogs"])
        ->and(translationScreen($page)['stats'])->not->toBe($before)
        ->and(missingRowsInPage($page))->toBe([])
        ->and(generationScreen($page)['saveAll'])->toBeNull();
});

it('keeps every draft when the server cannot be reached, and catches up once it can', function () {
    Queue::fake();
    $dogs = ($this->unit)($this->dogs);
    useScriptedTranslationProvider(ScriptedTranslationProvider::translating());
    $page = visitTranslationCenter('/admin/translation-center', 1440, 900);
    gateLivewireRequests($page);
    generateMissingInPage($page);

    // The status reads fail: the page says so, keeps its state and tries again.
    $page->script('window.livewireGate.fail = true');
    runTranslationGenerationJobs();
    waitForScript($page, "(document.querySelector('[data-strip=\"generation\"]')?.innerText ?? '').includes('Progress could not be refreshed')");

    expect(translationAiState($page, $dogs))->toMatchArray(['state' => ['Missing'], 'generating' => true])
        ->and(translationScreen($page)['focused'])->not->toBeNull();

    $page->script('window.livewireGate.fail = false');
    eventually(fn () => expect(translationAiState($page, $dogs)['state'])->toBe(['AI suggestion · not saved']), 8);

    expect(generationScreen($page)['notice'])->not->toContain('Progress could not be refreshed');

    // A discard or a save the server never hears of is not pretended.
    $page->script('window.livewireGate.fail = true');
    clickRowButton($page, $dogs, 'Discard');
    waitForScript($page, "document.querySelectorAll('.rg-admin-toast-stack .rg-admin-toast__text').length > 0");

    expect(translationAiState($page, $dogs))->toMatchArray(['state' => ['AI suggestion · not saved'], 'value' => "[{$this->target}] Dogs"])
        ->and(translationScreen($page)['toasts'])->toContain('Not discarded: the server did not answer. Try again.');

    clickRowButton($page, $dogs, 'Save');
    eventually(fn () => expect(translationScreen($page)['toasts'])->toContain('Not saved: the server did not answer. Try again.'));

    expect(translationAiState($page, $dogs)['state'])->toBe(['AI suggestion · not saved'])
        ->and($this->dogs->fresh()->name_translations)->toBeNull();
});

it('keeps the generation controls and suggestions on the screen at every width', function (int $width) {
    Queue::fake();
    $dogs = ($this->unit)($this->dogs);
    useScriptedTranslationProvider(ScriptedTranslationProvider::translating());
    $page = visitTranslationCenter('/admin/translation-center', $width, 900);
    generateMissingInPage($page);
    runTranslationGenerationJobs();
    eventually(fn () => expect(translationAiState($page, $dogs)['state'])->toBe(['AI suggestion · not saved']), 8);

    expect($page->script(<<<'JS'
        (() => {
            const inside = (el) => { const box = el.getBoundingClientRect(); return box.left >= 0 && box.right <= window.innerWidth }
            const shown = [...document.querySelectorAll('.rg-admin-translation-center__actions button, [data-strip="generation"] button')].filter((button) => getComputedStyle(button).display !== 'none')

            return {
                inside: shown.length >= 2 && shown.every(inside),
                overflow: document.documentElement.scrollWidth > window.innerWidth,
            }
        })()
    JS))->toBe(['inside' => true, 'overflow' => false]);
})->with([1440, 1280, 1024, 768, 390]);

// A link to one item -----------------------------------------------------------------

it('opens a link to one item on that item, focused and marked', function () {
    $cats = ($this->unit)($this->cats);
    $page = visitTranslationCenter("/admin/translation-center?locale={$this->other}&section=categories&mode=missing&unit=".rawurlencode($cats), 1440, 900);

    // The row is focused once Alpine has drawn it, a frame or more after the screen starts.
    waitForScript($page, 'document.activeElement?.id', translationFieldId($page, $cats));

    expect(translationScreen($page))->toMatchArray([
        'focused' => translationFieldId($page, $cats),
        // The link is followed once; the URL keeps only the filters.
        'search' => "?locale={$this->other}&section=categories&mode=missing",
    ])->and($page->script("document.querySelector('.rg-admin-translation-row--linked')?.dataset.unit"))->toBe($cats)
        ->and($page->script("(() => { const box = document.querySelector('[data-unit=\"{$cats}\"]').getBoundingClientRect(); return box.top >= 0 && box.bottom <= window.innerHeight })()"))->toBeTrue();
});

it('shows a linked item the filters would hide', function () {
    $birds = ($this->unit)($this->birds);
    $page = visitTranslationCenter("/admin/translation-center?locale={$this->target}&section=categories&mode=missing&unit=".rawurlencode($birds), 1440, 900);

    waitForScript($page, 'document.activeElement?.id', translationFieldId($page, $birds));

    expect(translationScreen($page)['rows'])->toContain($birds)
        ->and(translationScreen($page)['focused'])->toBe(translationFieldId($page, $birds));
});

/** The id of one row's field. */
function translationFieldId(mixed $page, string $unit): string
{
    return (string) $page->script("document.querySelector('[data-unit=\"{$unit}\"] input, [data-unit=\"{$unit}\"] textarea').id");
}
