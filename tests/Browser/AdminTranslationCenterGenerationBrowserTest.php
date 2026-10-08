<?php

use App\Actions\Translations\DiscardProjectTranslationGenerationAction;
use Illuminate\Support\Facades\Queue;

/*
 * Translation Center's background generation in a real browser: Generate
 * missing, the suggestions it brings back across reloads and languages, Save
 * all generated, and a server that cannot be reached.
 */

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

beforeEach(fn () => setUpTranslationCenterScreen($this));

// Generate missing ---------------------------------------------------------------------

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

    generateMissingInPage($page);

    // Leaving while it runs costs nothing: the work is the server's.
    expect(pageAsksBeforeLeaving($page))->toBeFalse();

    $again = visitTranslationCenter('/admin/translation-center', 1440, 900);

    expect(generationScreen($again)['notice'])->toContain('Generating 0 of')
        ->and(translationAiState($again, $dogs))->toMatchArray(['readonly' => true, 'generating' => true]);

    runTranslationGenerationJobs();
    eventually(fn () => expect(translationAiState($again, $dogs)['state'])->toBe(['AI suggestion · not saved']), 8);

    // Untouched suggestions are safe on the server: still no warning.
    expect(pageAsksBeforeLeaving($again))->toBeFalse();

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
