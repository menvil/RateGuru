<?php

use App\Models\Category;
use App\Models\ProjectSettings;
use App\Models\Tag;
use App\Models\User;

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

            return {
                rows: [...document.querySelectorAll('[role="table"] [role="row"][data-unit]')].filter((row) => visible(row)).map((row) => row.dataset.unit),
                count: document.querySelector('.rg-admin-toolbar__count')?.textContent.trim() ?? null,
                stats: [...document.querySelectorAll('.rg-admin-stats .rg-admin-stat')].map((stat) => stat.innerText.replace(/\s+/g, ' ').trim()),
                strip: visible(document.querySelector('.rg-admin-notice--strip')) ? document.querySelector('.rg-admin-notice--strip').innerText.replace(/\s+/g, ' ').trim() : null,
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

/** Types into one row's field. */
function typeTranslation(mixed $page, string $unit, string $text): void
{
    $selector = "[data-unit=\"{$unit}\"] input, [data-unit=\"{$unit}\"] textarea";
    $page->click($selector)->typeSlowly($selector, $text, 10)->wait(0.2);
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
    $page = visit('/admin/translation-center')->resize(1440, 900)->wait(0.5);

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
    $page = visit('/admin/translation-center')->resize($width, 900)->wait(0.5);

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
    $page->click('#rg-admin-translation-target-trigger')->wait(0.3);

    expect($page->script("(() => { const box = document.querySelector('.rg-admin-combobox__popover').getBoundingClientRect(); return box.right <= window.innerWidth && box.left >= 0 && document.documentElement.scrollWidth <= window.innerWidth })()"))->toBeTrue();
})->with([
    'wide' => [1440, 3],
    'laptop' => [1280, 3],
    'rail' => [1024, 3],
    'phone' => [390, 1],
]);

// The target language ----------------------------------------------------------------

it('chooses the target language from the keyboard, searching thirty-five languages in the page', function () {
    $codes = installLanguagesUpTo(35);
    $last = end($codes);
    $page = visit('/admin/translation-center')->resize(1440, 900)->wait(0.6);
    watchLivewireUpdates($page);

    $page->keys('#rg-admin-translation-target-trigger', 'Enter')->wait(0.3);

    expect(translationScreen($page)['focused'])->toBe('rg-admin-translation-target-search')
        ->and($page->script("document.querySelectorAll('#rg-admin-translation-target-listbox [role=option]').length"))->toBe(34)
        ->and($page->script("[...document.querySelectorAll('#rg-admin-translation-target-listbox [role=option]')].some((option) => option.dataset.value === 'en')"))->toBeFalse()
        ->and($page->script("getComputedStyle(document.getElementById('rg-admin-translation-target-listbox')).maxHeight"))->toBe('340px');

    // Typing filters the list in the browser.
    $page->typeSlowly('#rg-admin-translation-target-search', strtoupper($last), 30)->wait(0.3);

    expect($page->script("[...document.querySelectorAll('#rg-admin-translation-target-listbox [role=option]')].filter((option) => option.style.display !== 'none').map((option) => option.dataset.value)"))->toBe([$last])
        ->and(livewireUpdatesSinceWatching($page))->toBe(['fetches' => 0, 'requests' => 0, 'sameDocument' => true]);

    // Opened again on the chosen language, Up and Down move the active option and
    // Enter chooses it — here a disabled language.
    $page->keys('#rg-admin-translation-target-search', 'Escape')->wait(0.3);
    $page->keys('#rg-admin-translation-target-trigger', 'ArrowDown')->wait(0.3);
    $page->keys('#rg-admin-translation-target-search', 'ArrowDown')->wait(0.1);
    $page->keys('#rg-admin-translation-target-search', 'ArrowDown')->wait(0.1);
    $page->keys('#rg-admin-translation-target-search', 'ArrowUp')->wait(0.1);
    $page->keys('#rg-admin-translation-target-search', 'ArrowDown')->wait(0.1);

    $active = $page->script("document.getElementById(document.getElementById('rg-admin-translation-target-search').getAttribute('aria-activedescendant')).dataset.value");

    // English first in config, then the target the page opened on.
    expect($codes[1])->toBe($this->target)
        ->and($active)->toBe($codes[3]);

    $page->keys('#rg-admin-translation-target-search', 'Enter')->wait(1.2);

    expect(translationScreen($page))->toMatchArray(['search' => "?locale={$codes[3]}", 'focused' => 'rg-admin-translation-target-trigger'])
        ->and($page->script("document.getElementById('rg-admin-translation-target-trigger').innerText"))->toContain('Disabled')
        ->and(livewireUpdatesSinceWatching($page)['fetches'])->toBeGreaterThan(0);

    // Escape closes the list back to its trigger.
    $page->keys('#rg-admin-translation-target-trigger', 'ArrowDown')->wait(0.3);
    $page->keys(':focus', 'Escape')->wait(0.3);

    expect(translationScreen($page)['focused'])->toBe('rg-admin-translation-target-trigger')
        ->and($page->script("getComputedStyle(document.getElementById('rg-admin-translation-target-popover')).display"))->toBe('none');

    $page->assertNoJavaScriptErrors();
});

it('asks before switching languages with unsaved drafts, keeps them on Keep editing, and drops only them on Discard and switch', function () {
    $dogs = ($this->unit)($this->dogs);
    $page = visit('/admin/translation-center')->resize(1440, 900)->wait(0.5);

    typeTranslation($page, $dogs, 'Собаки');
    $page->click('#rg-admin-translation-target-trigger')->wait(0.3);
    $page->click("[role=option][data-value=\"{$this->other}\"]")->wait(0.5);

    expect(translationScreen($page))->toMatchArray(['dialog' => true, 'focused' => 'Keep editing', 'search' => "?locale={$this->target}"])
        ->and($page->script("document.querySelector('.rg-admin-dialog').innerText"))
        ->toContain('Discard unsaved translations?')
        ->toContain('1 '.config("locales.supported.{$this->target}.label").' edit is not saved.')
        ->toContain('Discard and switch');

    $page->click('.rg-admin-dialog .rg-admin-dialog__footer button:first-of-type')->wait(0.5);

    expect(translationScreen($page))->toMatchArray(['dialog' => false, 'search' => "?locale={$this->target}", 'focused' => 'rg-admin-translation-target-trigger'])
        ->and(translationRowState($page, $dogs)['value'])->toBe('Собаки');

    $page->click('#rg-admin-translation-target-trigger')->wait(0.3);
    $page->click("[role=option][data-value=\"{$this->other}\"]")->wait(0.5);
    $page->click('.rg-admin-dialog .rg-admin-button--primary')->wait(1.2);

    expect(translationScreen($page))->toMatchArray(['dialog' => false, 'search' => "?locale={$this->other}", 'strip' => null])
        ->and(translationRowState($page, $dogs))->toMatchArray(['value' => '', 'state' => ['Missing']])
        ->and($this->dogs->fresh()->name_translations)->toBeNull();

    // Without drafts it simply switches.
    $page->click('#rg-admin-translation-target-trigger')->wait(0.3);
    $page->click("[role=option][data-value=\"{$this->target}\"]")->wait(1.2);

    expect(translationScreen($page))->toMatchArray(['dialog' => false, 'search' => "?locale={$this->target}"]);

    $page->assertNoJavaScriptErrors();
});

// Filters ----------------------------------------------------------------------------

it('searches the English text, the keys and the translations, and filters by section and Missing only, without a single request to Livewire', function () {
    [$dogs, $cats, $birds, $tag] = [($this->unit)($this->dogs), ($this->unit)($this->cats), ($this->unit)($this->birds), ($this->unit)($this->tag)];
    $page = visit('/admin/translation-center')->resize(1440, 900)->wait(0.5);
    $total = count(translationScreen($page)['rows']);
    watchLivewireUpdates($page);

    // The English text.
    $page->typeSlowly('#rg-admin-translation-search', 'Dogs', 30)->wait(0.3);
    expect(translationScreen($page))->toMatchArray(['rows' => [$dogs], 'count' => "1 of {$total} items", 'search' => "?locale={$this->target}&q=Dogs"]);

    // A business key.
    $page->clear('#rg-admin-translation-search')->typeSlowly('#rg-admin-translation-search', 'categories.cats', 30)->wait(0.3);
    expect(translationScreen($page)['rows'])->toBe([$cats]);

    // A stored translation.
    $page->clear('#rg-admin-translation-search')->typeSlowly('#rg-admin-translation-search', 'Птицы', 30)->wait(0.3);
    expect(translationScreen($page)['rows'])->toBe([$birds]);

    // Section, then Missing only.
    $page->clear('#rg-admin-translation-search')->wait(0.3);
    $page->click('#rg-admin-translation-section')->wait(0.2);
    $page->click('[role=menuitemradio]:has-text("Categories")')->wait(0.3);
    expect(translationScreen($page))->toMatchArray(['rows' => [$dogs, $cats, $birds], 'search' => "?locale={$this->target}&section=categories"])
        ->and(translationScreen($page)['focused'])->toBe('rg-admin-translation-section');

    $page->click('.rg-admin-segmented [role=radio]:first-child')->wait(0.3);
    expect(translationScreen($page))->toMatchArray(['rows' => [$dogs, $cats], 'count' => "2 of {$total} items", 'search' => "?locale={$this->target}&section=categories&mode=missing"]);

    // The header counts the language, whatever the filters show.
    expect(translationScreen($page)['stats'][0])->toContain((string) $total)
        ->and(livewireUpdatesSinceWatching($page))->toBe(['fetches' => 0, 'requests' => 0, 'sameDocument' => true])
        ->and(in_array($tag, translationScreen($page)['rows'], true))->toBeFalse();

    $page->assertNoJavaScriptErrors();
});

it('opens with the filters in the URL, drops the ones it does not know, and clears them', function () {
    $page = visit("/admin/translation-center?locale={$this->target}&section=nowhere&mode=everything&q=zzzz")->resize(1440, 900)->wait(0.5);

    expect(translationScreen($page))->toMatchArray(['rows' => [], 'search' => "?locale={$this->target}&q=zzzz"])
        ->and($page->script("document.querySelector('.rg-admin-empty-state')?.innerText"))->toContain('No items match these filters');

    $page->click('Clear filters')->wait(0.3);

    expect(translationScreen($page))->toMatchArray(['search' => "?locale={$this->target}", 'focused' => 'rg-admin-translation-search'])
        ->and(count(translationScreen($page)['rows']))->toBeGreaterThan(3);
});

it('says when nothing is missing in Missing only, rather than that nothing matches', function () {
    $page = visit("/admin/translation-center?locale={$this->target}&section=static_pages&mode=missing")->resize(1440, 900)->wait(0.5);

    expect(translationScreen($page)['rows'])->toBe([])
        ->and($page->script("document.querySelector('.rg-admin-empty-state')?.innerText"))->toContain('Nothing missing here');
});

// Drafts -----------------------------------------------------------------------------

it('keeps a draft in the browser only: an edited state, a strip, Discard and Discard all, and nothing stored', function () {
    [$dogs, $cats] = [($this->unit)($this->dogs), ($this->unit)($this->cats)];
    $page = visit('/admin/translation-center')->resize(1440, 900)->wait(0.5);
    watchLivewireUpdates($page);

    expect(translationRowState($page, $dogs))->toMatchArray(['state' => ['Missing'], 'note' => 'Visitors see the English text', 'changed' => false, 'canSave' => false, 'discard' => false]);

    typeTranslation($page, $dogs, 'Собаки');
    typeTranslation($page, $cats, 'Кошки');

    expect(translationRowState($page, $dogs))->toMatchArray(['state' => ['Edited · not saved'], 'changed' => true, 'canSave' => true, 'discard' => true, 'counter' => '6 / 80 characters'])
        ->and(translationScreen($page)['strip'])->toBe('2 edits not saved yet. Nothing changes for visitors until you save. Discard all')
        ->and(livewireUpdatesSinceWatching($page))->toBe(['fetches' => 0, 'requests' => 0, 'sameDocument' => true])
        ->and($this->dogs->fresh()->name_translations)->toBeNull();

    // A filter that hides a draft keeps it.
    $page->typeSlowly('#rg-admin-translation-search', 'zoomies', 30)->wait(0.3);
    expect(translationScreen($page)['rows'])->toBe([($this->unit)($this->tag)])
        ->and(translationScreen($page)['strip'])->toStartWith('2 edits not saved yet.');
    $page->clear('#rg-admin-translation-search')->wait(0.3);
    expect(translationRowState($page, $dogs)['value'])->toBe('Собаки');

    // Discard goes back to what is stored, here nothing.
    clickRowButton($page, $dogs, 'Discard');
    $page->wait(0.3);
    expect(translationRowState($page, $dogs))->toMatchArray(['value' => '', 'state' => ['Missing']])
        ->and(translationScreen($page)['focused'])->toBe(translationFieldId($page, $dogs))
        ->and(translationScreen($page)['strip'])->toStartWith('1 edit not saved yet.');

    // Discard all, back to the search.
    $page->click('.rg-admin-notice--strip button')->wait(0.3);
    expect(translationRowState($page, $cats)['value'])->toBe('')
        ->and(translationScreen($page))->toMatchArray(['strip' => null, 'focused' => 'rg-admin-translation-search'])
        ->and(livewireUpdatesSinceWatching($page))->toBe(['fetches' => 0, 'requests' => 0, 'sameDocument' => true]);
});

it('discards an edit of a stored translation back to the stored text', function () {
    $birds = ($this->unit)($this->birds);
    $page = visit('/admin/translation-center')->resize(1440, 900)->wait(0.5);

    expect(translationRowState($page, $birds))->toMatchArray(['state' => ['Saved'], 'note' => 'Stored translation', 'value' => 'Птицы']);

    typeTranslation($page, $birds, ' и не только');

    expect(translationRowState($page, $birds))->toMatchArray(['state' => ['Edited · not saved'], 'note' => 'Saved version is kept until you save']);

    clickRowButton($page, $birds, 'Discard');
    $page->wait(0.3);

    expect(translationRowState($page, $birds))->toMatchArray(['state' => ['Saved'], 'value' => 'Птицы']);
});

it('warns before leaving the page with a draft, and opens again on what is stored', function () {
    $dogs = ($this->unit)($this->dogs);
    $page = visit('/admin/translation-center')->resize(1440, 900)->wait(0.5);
    $leaving = "(() => { const event = new Event('beforeunload', { cancelable: true }); window.dispatchEvent(event); return event.defaultPrevented })()";

    expect($page->script($leaving))->toBeFalse();

    typeTranslation($page, $dogs, 'Собаки');

    expect($page->script($leaving))->toBeTrue();

    $again = visit('/admin/translation-center')->resize(1440, 900)->wait(0.5);

    expect(translationRowState($again, $dogs))->toMatchArray(['value' => '', 'state' => ['Missing']]);
});

// Save -------------------------------------------------------------------------------

it('saves one row: stored, Saved, counted and announced, focus kept on the field', function () {
    $dogs = ($this->unit)($this->dogs);
    $page = visit('/admin/translation-center')->resize(1440, 900)->wait(0.5);
    $before = translationScreen($page)['stats'];

    typeTranslation($page, $dogs, 'Собаки');
    clickRowButton($page, $dogs, 'Save');
    $page->wait(0.8);

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
    $page = visit("/admin/translation-center?locale={$this->target}&section=categories&mode=missing")->resize(1440, 900)->wait(0.5);

    expect(translationScreen($page)['rows'])->toBe([$dogs, $cats]);

    typeTranslation($page, $dogs, 'Собаки');
    clickRowButton($page, $dogs, 'Save & next');
    $page->wait(0.8);

    expect(translationScreen($page))->toMatchArray(['rows' => [$cats], 'focused' => translationFieldId($page, $cats)])
        ->and($this->dogs->fresh()->name_translations)->toBe([$this->target => 'Собаки']);

    // The last one simply saves.
    $page->typeSlowly("[data-unit=\"{$cats}\"] input", 'Кошки', 10)->wait(0.2);
    clickRowButton($page, $cats, 'Save & next');
    $page->wait(0.8);

    expect($this->cats->fresh()->name_translations)->toBe([$this->target => 'Кошки'])
        ->and(translationScreen($page)['rows'])->toBe([])
        ->and($page->script("document.querySelector('.rg-admin-empty-state')?.innerText"))->toContain('Nothing missing here');

    $page->assertNoJavaScriptErrors();
});

it('keeps an error next to the field, blocks Save and stores nothing', function () {
    $dogs = ($this->unit)($this->dogs);
    $page = visit('/admin/translation-center')->resize(1440, 900)->wait(0.5);

    typeTranslation($page, $dogs, str_repeat('я', 84));

    $state = translationRowState($page, $dogs);

    expect($state)->toMatchArray(['error' => '4 over the limit', 'invalid' => 'true', 'canSave' => false, 'counter' => '84 / 80 characters'])
        ->and($state['describedBy'])->toEndWith('-error');

    clickRowButton($page, $dogs, 'Save');
    $page->wait(0.5);

    expect($this->dogs->fresh()->name_translations)->toBeNull();
});

it('asks a translation to keep every placeholder of the English text', function () {
    $pages = ProjectSettings::findOrFail(1)->static_pages;
    $pages['contact']['en']['content'] = 'Write to {contact_email}.';
    ProjectSettings::query()->update(['static_pages' => json_encode($pages)]);
    $page = visit("/admin/translation-center?locale={$this->target}&section=static_pages")->resize(1440, 900)->wait(0.5);
    $field = '[data-unit="static_pages:contact:content"] textarea';

    $page->clear($field)->typeSlowly($field, 'Пишите нам.', 10)->wait(0.2);

    expect(translationRowState($page, 'static_pages:contact:content'))->toMatchArray(['error' => 'Keep {contact_email}', 'invalid' => 'true', 'canSave' => false])
        ->and($page->script('document.querySelector(\'[data-unit="static_pages:contact:content"] .rg-admin-constraint-chip--placeholder\').textContent'))->toBe('{contact_email}');
});

// Context ----------------------------------------------------------------------------

it('opens the context of one item in the drawer, read only, and closes it back to its button', function () {
    $birds = ($this->unit)($this->birds);
    $page = visit('/admin/translation-center')->resize(1440, 900)->wait(0.5);
    $button = "[data-unit=\"{$birds}\"] .rg-admin-translation-row__context";

    $page->click($button)->wait(0.8);

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

    $page->keys(':focus', 'Escape')->wait(0.6);

    expect(translationScreen($page)['drawer'])->toBeFalse()
        ->and($page->script('document.activeElement === document.querySelector('.json_encode($button).')'))->toBeTrue();

    $page->assertNoJavaScriptErrors();
});

it('fits the drawer to a phone', function () {
    $page = visit('/admin/translation-center')->resize(390, 844)->wait(0.5);
    $page->click('[data-unit="'.($this->unit)($this->dogs).'"] .rg-admin-translation-row__context')->wait(0.8);

    expect($page->script("Math.round(document.querySelector('.rg-admin-drawer').getBoundingClientRect().width)"))->toBe(390)
        ->and(translationScreen($page)['overflow'])->toBeFalse();
});

// A link to one item -----------------------------------------------------------------

it('opens a link to one item on that item, focused and marked', function () {
    $cats = ($this->unit)($this->cats);
    $page = visit("/admin/translation-center?locale={$this->other}&section=categories&mode=missing&unit=".rawurlencode($cats))->resize(1440, 900)->wait(0.8);

    expect(translationScreen($page))->toMatchArray([
        'focused' => translationFieldId($page, $cats),
        // The link is followed once; the URL keeps only the filters.
        'search' => "?locale={$this->other}&section=categories&mode=missing",
    ])->and($page->script("document.querySelector('.rg-admin-translation-row--linked')?.dataset.unit"))->toBe($cats)
        ->and($page->script("(() => { const box = document.querySelector('[data-unit=\"{$cats}\"]').getBoundingClientRect(); return box.top >= 0 && box.bottom <= window.innerHeight })()"))->toBeTrue();
});

it('shows a linked item the filters would hide', function () {
    $birds = ($this->unit)($this->birds);
    $page = visit("/admin/translation-center?locale={$this->target}&section=categories&mode=missing&unit=".rawurlencode($birds))->resize(1440, 900)->wait(0.8);

    expect(translationScreen($page)['rows'])->toContain($birds)
        ->and(translationScreen($page)['focused'])->toBe(translationFieldId($page, $birds));
});

/** The id of one row's field. */
function translationFieldId(mixed $page, string $unit): string
{
    return (string) $page->script("document.querySelector('[data-unit=\"{$unit}\"] input, [data-unit=\"{$unit}\"] textarea').id");
}
