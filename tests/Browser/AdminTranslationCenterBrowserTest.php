<?php

/*
 * Translation Center in a real browser: the Admin v2 layout at each width, the
 * target language combobox, the filters that never ask the server, and a link
 * to one item — measured from the page the browser built.
 *
 * The drafts, the AI suggestions and the background generation are in the
 * files beside this one, so that the suite's workers can share the screen out;
 * what they have in common is in tests/Pest.php.
 */

beforeEach(fn () => setUpTranslationCenterScreen($this));

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

it('keeps Item, English and the target in that order at every width, without the page scrolling sideways', function () {
    $page = visitTranslationCenter('/admin/translation-center', 1440, 900);

    // One page, resized through the widths. visitTranslationCenter() has always
    // measured a page resized after it loaded, and nothing on the page reads the
    // width but CSS, so this is the same check without a page load per width.
    foreach (['wide' => [1440, 3], 'laptop' => [1280, 3], 'rail' => [1024, 3], 'phone' => [390, 1]] as $screen => [$width, $columns]) {
        resizeAndSettle($page, $width, 900);

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
        JS))->toBe(['columns' => $columns, 'order' => true, 'overflow' => false], "on the {$screen} screen");

        // The combobox opens inside the screen, never wider than it.
        openTranslationTargetList($page);

        expect($page->script("(() => { const box = document.querySelector('.rg-admin-combobox__popover').getBoundingClientRect(); return box.right <= window.innerWidth && box.left >= 0 && document.documentElement.scrollWidth <= window.innerWidth })()"))->toBeTrue("on the {$screen} screen");

        $page->keys('#rg-admin-translation-target-search', 'Escape');
        waitForTranslationTargetList($page, open: false);
    }
});

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

it('chooses the target language from the keyboard, searching sixty languages in the page', function () {
    $codes = installLanguagesUpTo(60);
    $last = end($codes);
    $page = visitTranslationCenter('/admin/translation-center', 1440, 900);
    watchLivewireUpdates($page);

    $page->keys('#rg-admin-translation-target-trigger', 'Enter');
    waitForTranslationTargetList($page, open: true);

    expect(translationScreen($page)['focused'])->toBe('rg-admin-translation-target-search')
        ->and($page->script("document.querySelectorAll('#rg-admin-translation-target-listbox [role=option]').length"))->toBe(59)
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
    gateLivewireRequests($page);
    $page->script('window.livewireGate.fail = true');

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
    $page->script('window.livewireGate.fail = false');
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
