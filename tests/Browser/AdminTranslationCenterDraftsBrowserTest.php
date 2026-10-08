<?php

use App\Models\ProjectSettings;

/*
 * Translation Center's drafts in a real browser: they live only in the browser
 * until Save, are checked as they are typed, are asked about before they are
 * lost, and the context drawer explains the item they translate.
 */

beforeEach(fn () => setUpTranslationCenterScreen($this));

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

    expect(pageAsksBeforeLeaving($page))->toBeFalse();

    typeTranslation($page, $dogs, 'Собаки');

    expect(pageAsksBeforeLeaving($page))->toBeTrue();

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
