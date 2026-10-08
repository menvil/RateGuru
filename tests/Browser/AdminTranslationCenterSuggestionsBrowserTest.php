<?php

use App\Support\TranslationEngine\Enums\TranslationErrorCode;

/*
 * Translation Center's AI suggestions for one row in a real browser: a draft
 * until saved, regenerated, offered as an alternative to a saved translation,
 * failing safely, and shown in the context drawer as what is sent.
 */

/** What the page's status region says aloud. */
function translationAnnouncement(mixed $page): string
{
    return (string) $page->script("document.querySelector('.rg-admin-translation-center span.rg-admin-sr-only[role=\"status\"]').textContent.trim()");
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

beforeEach(fn () => setUpTranslationCenterScreen($this));

afterEach(fn () => removeCatalogScratchDirectory($this));

// The screen -------------------------------------------------------------------------

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

// AI suggestions ---------------------------------------------------------------------

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

    // The toast is drawn at once; the row hides its "Generating…" indicator a frame later.
    eventually(fn () => expect(translationScreen($page)['toasts'])->toContain('The translation provider is unavailable. Try again.')
        ->and(translationAiState($page, $dogs))->toMatchArray([
            'value' => 'Псы', 'state' => ['AI suggestion · not saved'], 'generating' => false, 'readonly' => false, 'busy' => 'false', 'ai' => 'Regenerate', 'aiDisabled' => 'false',
        ])
        ->and($page->script('document.activeElement.querySelector("span")?.textContent.trim()'))->toBe('Regenerate')
        ->and($this->dogs->fresh()->name_translations)->toBeNull());
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

    // The toast is drawn at once; the row hides its "Generating…" indicator a frame later.
    eventually(fn () => expect(translationScreen($page)['toasts'])->toContain('AI suggested the same text as the saved translation.')
        ->and(translationAiState($page, $birds))->toMatchArray(['state' => ['Saved'], 'value' => 'Птицы', 'generating' => false, 'ai' => 'Suggest alternative']));
});

it('refuses an alternative once someone else has changed the saved translation, and says to reload', function () {
    $birds = ($this->unit)($this->birds);
    useScriptedTranslationProvider(answeringTranslationProvider(['Пернатые']));
    $page = visitTranslationCenter('/admin/translation-center', 1440, 900);

    $this->birds->update(['name_translations' => [$this->target => 'Изменено в другой вкладке', $this->other => 'Птици']]);

    focusAiButton($page, $birds)->script('document.activeElement.click()');
    waitForScript($page, "document.querySelectorAll('.rg-admin-toast-stack .rg-admin-toast__text').length > 0");

    // The toast is drawn at once; the row hides its "Generating…" indicator a frame later.
    eventually(fn () => expect(translationScreen($page)['toasts'])->toContain('This translation was changed by someone else. Reload the page to review it.')
        ->and(translationAiState($page, $birds))->toMatchArray(['state' => ['Saved'], 'value' => 'Птицы', 'generating' => false]));
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

    suggestTranslationIn($page, $dogs, 'Собаки');

    expect(pageAsksBeforeLeaving($page))->toBeTrue();

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

    // The toast is drawn at once; the row hides its "Generating…" indicator a frame later.
    eventually(fn () => expect(translationScreen($page)['toasts'])->toContain('No suggestion: the server did not answer. Try again.')
        ->and(translationAiState($page, $dogs))->toMatchArray([
            'state' => ['Missing'], 'value' => '', 'generating' => false, 'readonly' => false, 'busy' => 'false', 'ai' => 'AI translate', 'aiDisabled' => 'false',
        ])
        ->and(translationScreen($page)['strip'])->toBeNull());

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

it('opens the context of one item, AI context included, with sixty languages installed', function () {
    $codes = installLanguagesUpTo(60);
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

    expect($page->script("document.querySelectorAll('.rg-admin-translation-context__payload-list li').length"))->toBe(58)
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
