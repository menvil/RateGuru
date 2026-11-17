<?php

use Illuminate\Support\Facades\Queue;

/*
 * Outdated translations in Translation Center, in a real browser: a stored
 * translation made from English that changed since says so, shows that
 * English, stays what visitors see, has a filter of its own, and is current
 * once the row is saved — as it is, or with a background suggestion.
 */

/** The English an outdated row says its translation was made from, or null when it shows none. */
function translatedFromInPage(mixed $page, string $unit): ?string
{
    return $page->script(sprintf(<<<'JS'
        (() => {
            const previous = document.querySelector('[data-unit="%s"] .rg-admin-translation-row__previous')

            return previous && getComputedStyle(previous).display !== 'none' ? previous.innerText.replace(/\s+/g, ' ').trim() : null
        })()
    JS, $unit));
}

/** The Generate missing button's label, or null when it is not shown. */
function generateLabelInPage(mixed $page): ?string
{
    return $page->script("(() => { const button = [...document.querySelectorAll('.rg-admin-translation-center__actions button')].find((button) => button.innerText.trim().startsWith('Generate')); return button && getComputedStyle(button).display !== 'none' ? button.innerText.trim() : null })()");
}

beforeEach(function () {
    setUpTranslationCenterScreen($this);
    $this->outdated = outdatedCategory($this->target, 'Willkommen', 'Welcome', 'Hello');
    $this->stale = ($this->unit)($this->outdated);
});

it('marks an outdated translation, filters to it, and makes it current when it is saved as it is', function () {
    $page = visitTranslationCenter('/admin/translation-center', 1440, 900);
    $missing = $page->script("(() => { const data = Alpine.\$data(document.querySelector('.rg-admin-translation-center')); return data.missing })()");

    expect(translationRowState($page, $this->stale))->toMatchArray([
        'state' => ['English changed'], 'note' => 'Made from older English. Visitors see it until you save.', 'value' => 'Willkommen', 'canSave' => true, 'discard' => false,
    ])
        ->and(translatedFromInPage($page, $this->stale))->toBe('TRANSLATED FROM Welcome')
        ->and(translationScreen($page)['stats'])->toContain('NEEDS UPDATE 1')
        ->and(generateLabelInPage($page))->toBe("Generate missing ({$missing}) & outdated (1)");

    // Needs update, kept in the URL.
    $page->click('.rg-admin-segmented [role=radio]:nth-child(2)');
    eventually(fn () => expect(translationScreen($page))->toMatchArray(['rows' => [$this->stale], 'search' => "?locale={$this->target}&mode=outdated"]));

    // Saved unchanged: kept for the new English, current from now on, and out of Needs update.
    clickRowButton($page, $this->stale, 'Save');
    eventually(fn () => expect(translationScreen($page)['rows'])->toBe([]));

    expect(translationScreen($page)['stats'])->toContain('NEEDS UPDATE 0')
        ->and($page->script("document.querySelector('.rg-admin-empty-state')?.innerText"))->toContain('Nothing to update here')
        ->and(generateLabelInPage($page))->toBe("Generate missing ({$missing})")
        ->and($this->outdated->fresh()->name_translations)->toBe([$this->target => 'Willkommen'])
        ->and(translationSourceOf($this->stale, $this->target)['reference'])->toBe('Hello');

    $page->click('.rg-admin-segmented [role=radio]:last-child');
    eventually(fn () => expect(translationRowState($page, $this->stale))->toMatchArray(['state' => ['Saved'], 'canSave' => false]));

    expect(translatedFromInPage($page, $this->stale))->toBeNull();

    $page->script('Alpine.nextTick()');
    $page->assertNoJavaScriptErrors();
});

it('keeps an outdated row in Needs update while it is edited, the English it was made from still shown', function () {
    $page = visitTranslationCenter("/admin/translation-center?locale={$this->target}&mode=outdated", 1440, 900);

    typeTranslation($page, $this->stale, ' neu');

    expect(translationScreen($page)['rows'])->toBe([$this->stale])
        ->and(translationRowState($page, $this->stale))->toMatchArray(['state' => ['Edited · not saved'], 'note' => 'Saved version is kept until you save'])
        ->and(translatedFromInPage($page, $this->stale))->toBe('TRANSLATED FROM Welcome');
});

it('generates a suggestion for an outdated translation in the background, which replaces it once saved', function () {
    Queue::fake();
    useScriptedTranslationProvider(ScriptedTranslationProvider::translating());
    $page = visitTranslationCenter('/admin/translation-center', 1440, 900);

    $page->script("[...document.querySelectorAll('.rg-admin-translation-center__actions button')].find((button) => button.innerText.trim().startsWith('Generate')).click()");
    waitForScript($page, 'document.activeElement?.innerText.trim()', 'Cancel');

    expect($page->script("document.querySelector('.rg-admin-dialog').innerText"))
        ->toContain('outdated one')
        ->toContain('Until then, visitors keep seeing the outdated translations.');

    $page->click('.rg-admin-dialog .rg-admin-button--primary');
    waitForScript($page, "(() => { const notice = document.querySelector('[data-strip=\"generation\"]'); return !! notice && getComputedStyle(notice).display !== 'none' })()");
    runTranslationGenerationJobsAndPoll($page);

    eventually(fn () => expect(translationRowState($page, $this->stale))->toMatchArray(['state' => ['AI suggestion · not saved'], 'value' => "[{$this->target}] Hello"]), 8);

    // Until it is saved, the old translation is what is stored.
    expect($this->outdated->fresh()->name_translations)->toBe([$this->target => 'Willkommen']);

    clickRowButton($page, $this->stale, 'Save');
    eventually(fn () => expect(translationRowState($page, $this->stale)['state'])->toBe(['Saved']), 8);

    expect($this->outdated->fresh()->name_translations)->toBe([$this->target => "[{$this->target}] Hello"])
        ->and(translatedFromInPage($page, $this->stale))->toBeNull();
});

it('keeps four figures on one phone line, the completion under them, once one needs an update', function () {
    $page = visitTranslationCenter('/admin/translation-center', 390, 844);

    expect($page->script(<<<'JS'
        (() => {
            const stats = [...document.querySelectorAll('.rg-admin-stats .rg-admin-stat')].map((stat) => stat.getBoundingClientRect())
            const figures = stats.slice(0, 4)

            return {
                count: stats.length,
                oneLine: figures.every((stat) => Math.abs(stat.top - figures[0].top) < 1),
                completionUnder: stats[4].top >= Math.max(...figures.map((stat) => stat.bottom)),
                inside: stats.every((stat) => stat.right <= window.innerWidth),
                overflow: document.documentElement.scrollWidth > window.innerWidth,
            }
        })()
    JS))->toBe(['count' => 5, 'oneLine' => true, 'completionUnder' => true, 'inside' => true, 'overflow' => false]);
});
