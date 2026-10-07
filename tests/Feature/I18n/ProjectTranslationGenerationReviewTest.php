<?php

use App\Actions\Translations\DiscardProjectTranslationGenerationAction;
use App\Actions\Translations\SaveProjectTranslationGenerationAction;
use App\Actions\Translations\UpdateProjectTranslationAction;
use App\Exceptions\Translations\CannotGenerateTranslationsException;
use App\Filament\Pages\TranslationCenterPage;
use App\Models\Category;
use App\Models\ProjectSettings;
use App\Models\User;
use App\Support\TranslationEngine\Data\TranslationBatchRequest;
use App\Support\TranslationEngine\Data\TranslationProviderCall;
use App\Support\TranslationEngine\Data\TranslationProviderLimits;
use App\Support\TranslationEngine\Enums\TranslationErrorCode;
use App\Support\TranslationEngine\Exceptions\TranslationProviderException;
use App\Support\Translations\Generation\ProjectTranslationGenerationIssue;
use App\Support\Translations\ProjectTranslationCatalog;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/**
 * Reviewing background suggestions: they belong to the administrator who
 * generated them, survive the page, and become project content only through
 * the ordinary writer — each one on its own, never over a translation someone
 * else saved, never from English that has changed.
 */
function saveGeneration(User $admin, string $locale, string $batch, ?string $unit = null, array $except = []): array
{
    return app(SaveProjectTranslationGenerationAction::class)->handle($admin, $locale, $batch, $unit, $except);
}

function discardGeneration(User $admin, string $locale, string $batch, mixed $unit = null): ?array
{
    return app(DiscardProjectTranslationGenerationAction::class)->handle($admin, $locale, $batch, $unit);
}

/**
 * Runs $meanwhile once, the moment the save's early read of the categories has fetched its rows — after
 * that read, before the writer locks a row: a change landing in exactly the gap only the locked check
 * can close. Returns whether it ran.
 *
 * @return Closure(): bool
 */
function inTheGapAfterTheEarlyRead(Closure $meanwhile): Closure
{
    $ran = false;

    DB::listen(function (QueryExecuted $query) use (&$ran, $meanwhile): void {
        $sql = strtolower($query->sql);

        if (! $ran && preg_match('/from [`"]?categories[`"]? .* in \(/', $sql) === 1 && ! str_contains($sql, 'for update')) {
            $ran = true;
            $meanwhile();
        }
    });

    return function () use (&$ran): bool {
        return $ran;
    };
}

function reviewRefusal(Closure $operation): ?string
{
    try {
        $operation();
    } catch (CannotGenerateTranslationsException $exception) {
        return $exception->reason;
    }

    return null;
}

beforeEach(function () {
    ProjectSettings::factory()->create();
    Queue::fake();
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
    [$this->target] = twoTranslatedLocales();

    // Only the categories below are missing.
    foreach (app(ProjectTranslationCatalog::class)->units() as $unit) {
        if ($unit->requiresTranslation() && $unit->translation($this->target) === null) {
            app(UpdateProjectTranslationAction::class)->handle($this->admin, $unit->id, $this->target, 'Übersetzt');
        }
    }

    $this->categories = collect(['A' => 'Apples', 'B' => 'Bananas', 'C' => 'Cherries', 'D' => 'Dates', 'E' => 'Elderberries'])
        ->map(fn (string $name): Category => Category::factory()->create(['name' => $name, 'name_translations' => null, 'is_active' => true]));
    $this->unit = fn (string $key): string => "categories:{$this->categories[$key]->id}:name";

    // Item D's chunk fails; the others are translated.
    useScriptedTranslationProvider(new ScriptedTranslationProvider(
        function (TranslationBatchRequest $request) {
            if ($request->items[0]->sourceText === 'Dates') {
                throw new TranslationProviderException(
                    TranslationErrorCode::ProviderUnavailable,
                    TranslationProviderCall::failed('scripted', 'scripted-model', $request->itemIds(), 3, TranslationErrorCode::ProviderUnavailable),
                );
            }

            return scriptedTranslationResponse($request, array_map(fn ($item) => ['id' => $item->id, 'text' => "KI {$item->sourceText}"], $request->items));
        },
        new TranslationProviderLimits(1, 60_000),
    ));

    $this->batch = startTranslationGeneration($this->admin, $this->target)['batch'];
    runTranslationGenerationJobs();
});

it('restores the same suggestions on a fresh page, for the same administrator and language', function () {
    $fresh = Livewire::withQueryParams(['locale' => $this->target])->test(TranslationCenterPage::class);
    $restored = $fresh->viewData('client')['generation'];

    expect($restored['batch'])->toBe($this->batch)
        ->and($restored['counts'])->toMatchArray(['ready' => 4, 'failed' => 1])
        ->and(translationGenerationItem($restored, ($this->unit)('A')))->toMatchArray(['status' => 'ready', 'text' => 'KI Apples'])
        ->and(translationGenerationItem($restored, ($this->unit)('D')))->toMatchArray(['status' => 'failed', 'message' => 'The translation provider is unavailable. Try again.']);

    // The client also knows which English each row shows, to compare.
    $row = collect($fresh->viewData('client')['units'])->firstWhere('id', ($this->unit)('A'));

    expect($row['fingerprint'])->toBe(translationGenerationItem($restored, ($this->unit)('A'))['fingerprint']);
});

it('saves one suggestion from the server\'s copy through the ordinary writer', function () {
    $result = saveGeneration($this->admin, $this->target, $this->batch, ($this->unit)('A'));

    expect($result['results'])->toBe([['unit' => ($this->unit)('A'), 'outcome' => 'saved', 'value' => 'KI Apples', 'message' => null]])
        ->and($this->categories['A']->fresh()->name_translations)->toBe([$this->target => 'KI Apples'])
        ->and(translationGenerationItem($result['generation'], ($this->unit)('A'))['status'])->toBe('saved')
        // Saved, it is never offered again.
        ->and(translationGenerationOf($this->admin, $this->target)['counts']['ready'])->toBe(3);
});

it('saves the server\'s text, whatever a forged call carries', function () {
    Livewire::withQueryParams(['locale' => $this->target])->test(TranslationCenterPage::class)
        ->call('saveGenerated', $this->target, $this->batch, ($this->unit)('A'), 'Forged text');

    expect($this->categories['A']->fresh()->name_translations)->toBe([$this->target => 'KI Apples']);
});

it('saves every ready suggestion on its own, skipping changed English and translations saved meanwhile', function () {
    $this->categories['B']->update(['name' => 'Plantains']);
    app(UpdateProjectTranslationAction::class)->handle($this->admin, ($this->unit)('C'), $this->target, 'Von jemand anderem');

    $result = saveGeneration($this->admin, $this->target, $this->batch);
    $outcomes = collect($result['results'])->mapWithKeys(fn (array $item): array => [$item['unit'] => $item['outcome']])->all();

    expect($outcomes)->toBe([
        ($this->unit)('A') => 'saved',
        ($this->unit)('B') => 'source_changed',
        ($this->unit)('C') => 'already_translated',
        ($this->unit)('E') => 'saved',
    ])
        ->and([$result['saved'], $result['skipped']])->toBe([2, 2])
        ->and($this->categories['A']->fresh()->name_translations)->toBe([$this->target => 'KI Apples'])
        ->and($this->categories['B']->fresh()->name_translations)->toBeNull()
        ->and($this->categories['C']->fresh()->name_translations)->toBe([$this->target => 'Von jemand anderem'])
        ->and($this->categories['D']->fresh()->name_translations)->toBeNull()
        ->and($this->categories['E']->fresh()->name_translations)->toBe([$this->target => 'KI Elderberries'])
        ->and(collect($result['results'])->firstWhere('unit', ($this->unit)('C'))['value'])->toBe('Von jemand anderem')
        ->and(translationGenerationItem($result['generation'], ($this->unit)('D'))['status'])->toBe('failed')
        ->and(translationGenerationItem($result['generation'], ($this->unit)('B')))->toMatchArray([
            'status' => 'skipped', 'message' => 'English changed after this suggestion was generated. Generate a new translation.',
        ]);
});

it('leaves out the rows the administrator has edited since', function () {
    $result = saveGeneration($this->admin, $this->target, $this->batch, null, [($this->unit)('A')]);

    expect(array_column($result['results'], 'unit'))->not->toContain(($this->unit)('A'))
        ->and($this->categories['A']->fresh()->name_translations)->toBeNull()
        ->and(translationGenerationItem($result['generation'], ($this->unit)('A'))['status'])->toBe('ready');
});

it('never saves a suggestion made from English that changed afterwards', function () {
    $this->categories['A']->update(['name' => 'Apple lovers']);

    $result = saveGeneration($this->admin, $this->target, $this->batch, ($this->unit)('A'));

    expect($result['results'][0]['outcome'])->toBe('source_changed')
        ->and($this->categories['A']->fresh()->name_translations)->toBeNull();
});

// The early read guarantees nothing: the locked row decides ---------------------------

it('never writes over a translation saved between its early read and the write', function (string $which) {
    $unit = ($this->unit)('A');
    $other = User::factory()->admin()->create();
    $ran = inTheGapAfterTheEarlyRead(fn () => app(UpdateProjectTranslationAction::class)->handle($other, $unit, $this->target, 'Von jemand anderem'));

    $result = saveGeneration($this->admin, $this->target, $this->batch, $which === 'one' ? $unit : null);

    expect($ran())->toBeTrue()
        ->and(collect($result['results'])->firstWhere('unit', $unit))->toBe([
            'unit' => $unit, 'outcome' => 'already_translated', 'value' => 'Von jemand anderem', 'message' => ProjectTranslationGenerationIssue::AlreadyTranslated->message(),
        ])
        ->and($this->categories['A']->fresh()->name_translations)->toBe([$this->target => 'Von jemand anderem'])
        ->and(translationGenerationItem($result['generation'], $unit))->toMatchArray(['status' => 'skipped', 'issue' => 'already_translated'])
        // Save all goes on with the rest, each on its own.
        ->and($result['saved'])->toBe($which === 'one' ? 0 : 3)
        ->and($this->categories['B']->fresh()->name_translations)->toBe($which === 'one' ? null : [$this->target => 'KI Bananas']);
})->with(['Save' => 'one', 'Save all generated' => 'all']);

it('never saves a suggestion whose English changed between its early read and the write', function (string $which) {
    $unit = ($this->unit)('A');
    $ran = inTheGapAfterTheEarlyRead(fn () => Category::query()->whereKey($this->categories['A']->id)->update(['name' => 'Apple lovers']));

    $result = saveGeneration($this->admin, $this->target, $this->batch, $which === 'one' ? $unit : null);

    // New English that is not blank must not let a suggestion for the old one through.
    expect($ran())->toBeTrue()
        ->and(collect($result['results'])->firstWhere('unit', $unit))->toBe([
            'unit' => $unit, 'outcome' => 'source_changed', 'value' => '', 'message' => ProjectTranslationGenerationIssue::SourceChanged->message(),
        ])
        ->and($this->categories['A']->fresh()->only(['name', 'name_translations']))->toBe(['name' => 'Apple lovers', 'name_translations' => null])
        ->and(translationGenerationItem($result['generation'], $unit))->toMatchArray(['status' => 'skipped', 'issue' => 'source_changed'])
        ->and($result['saved'])->toBe($which === 'one' ? 0 : 3);
})->with(['Save' => 'one', 'Save all generated' => 'all']);

it('writes nothing for a suggestion whose unit is gone, and refuses one that is not ready', function () {
    $this->categories['A']->delete();

    expect(saveGeneration($this->admin, $this->target, $this->batch, ($this->unit)('A'))['results'][0]['outcome'])->toBe('unit_unavailable')
        ->and(reviewRefusal(fn () => saveGeneration($this->admin, $this->target, $this->batch, ($this->unit)('D'))))->toBe('not_ready')
        ->and(reviewRefusal(fn () => saveGeneration($this->admin, $this->target, $this->batch, 'categories:999999:name')))->toBe('not_ready');
});

it('discards one suggestion so a fresh page does not bring it back, and writes nothing', function () {
    discardGeneration($this->admin, $this->target, $this->batch, ($this->unit)('A'));

    expect(translationGenerationItem(translationGenerationOf($this->admin, $this->target), ($this->unit)('A'))['status'])->toBe('discarded')
        ->and($this->categories['A']->fresh()->name_translations)->toBeNull();
});

it('refuses to discard anything but one ready suggestion or, by null alone, every one', function (Closure $unit) {
    $before = translationGenerationOf($this->admin, $this->target);

    expect(reviewRefusal(fn () => discardGeneration($this->admin, $this->target, $this->batch, $unit($this))))->toBe('not_ready')
        ->and(translationGenerationOf($this->admin, $this->target))->toBe($before)
        ->and($before['counts']['ready'])->toBe(4);
})->with([
    'an empty list' => [fn ($test) => []],
    'a list of units' => [fn ($test) => [($test->unit)('A')]],
    'a map' => [fn ($test) => ['unit' => ($test->unit)('A')]],
    'a number' => [fn ($test) => 123],
    'true' => [fn ($test) => true],
    'false' => [fn ($test) => false],
    'an empty string' => [fn ($test) => ''],
    'a unit the batch does not have' => [fn ($test) => 'categories:999999:name'],
    'a failed one' => [fn ($test) => ($test->unit)('D')],
]);

it('refuses to discard a suggestion that was already saved, skipped or discarded, changing nothing', function () {
    $this->categories['B']->update(['name' => 'Plantains']);
    saveGeneration($this->admin, $this->target, $this->batch, ($this->unit)('A'));
    saveGeneration($this->admin, $this->target, $this->batch, ($this->unit)('B'));
    discardGeneration($this->admin, $this->target, $this->batch, ($this->unit)('C'));
    $before = translationGenerationOf($this->admin, $this->target);

    expect(array_map(fn (string $key): string => translationGenerationItem($before, ($this->unit)($key))['status'], ['A', 'B', 'C']))->toBe(['saved', 'skipped', 'discarded']);

    foreach (['A', 'B', 'C'] as $key) {
        expect(reviewRefusal(fn () => discardGeneration($this->admin, $this->target, $this->batch, ($this->unit)($key))))->toBe('not_ready');
    }

    expect(translationGenerationOf($this->admin, $this->target))->toBe($before)
        ->and($this->categories['A']->fresh()->name_translations)->toBe([$this->target => 'KI Apples']);
});

it('answers a malformed discard from the page with a refusal, and discards everything only for null', function () {
    $page = Livewire::withQueryParams(['locale' => $this->target])->test(TranslationCenterPage::class);

    foreach ([[], 123, true, 'categories:999999:name'] as $unit) {
        expect($page->call('discardGenerated', $this->target, $this->batch, $unit)->effects['returns'][0])->toBe([
            'discarded' => false, 'error' => 'This suggestion is no longer available. Reload the page to review it.', 'reason' => 'not_ready',
        ]);
    }

    expect(translationGenerationOf($this->admin, $this->target)['counts']['ready'])->toBe(4)
        ->and($page->call('discardGenerated', $this->target, $this->batch, ($this->unit)('A'))->effects['returns'][0]['discarded'])->toBeTrue()
        ->and(translationGenerationOf($this->admin, $this->target)['counts'])->toMatchArray(['ready' => 3, 'discarded' => 1])
        ->and($page->call('discardGenerated', $this->target, $this->batch, null)->effects['returns'][0])->toBe(['discarded' => true, 'generation' => null])
        ->and(translationGenerationOf($this->admin, $this->target))->toBeNull();
});

it('discards every ready suggestion, keeps what was saved, and lets the language generate again', function () {
    saveGeneration($this->admin, $this->target, $this->batch, ($this->unit)('A'));

    expect(discardGeneration($this->admin, $this->target, $this->batch))->toBeNull()
        ->and(translationGenerationOf($this->admin, $this->target))->toBeNull()
        ->and($this->categories['A']->fresh()->name_translations)->toBe([$this->target => 'KI Apples'])
        ->and($this->categories['B']->fresh()->name_translations)->toBeNull();

    // Nothing ready and nothing running: Generate missing starts afresh, the failed item included.
    $again = startTranslationGeneration($this->admin, $this->target);

    expect($again['batch'])->not->toBe($this->batch)
        ->and(array_column($again['items'], 'unit'))->toContain(($this->unit)('D'))->not->toContain(($this->unit)('A'));
});

it('supersedes a suggestion once the row is saved some other way', function () {
    Livewire::withQueryParams(['locale' => $this->target])->test(TranslationCenterPage::class)
        ->call('save', ($this->unit)('A'), $this->target, 'Von Hand korrigiert');

    expect(translationGenerationItem(translationGenerationOf($this->admin, $this->target), ($this->unit)('A'))['status'])->toBe('discarded')
        ->and($this->categories['A']->fresh()->name_translations)->toBe([$this->target => 'Von Hand korrigiert']);
});

it('belongs to the administrator who generated it: another one can neither read, save nor discard it', function () {
    $other = User::factory()->admin()->create();

    expect(translationGenerationOf($other, $this->target))->toBeNull()
        ->and(reviewRefusal(fn () => saveGeneration($other, $this->target, $this->batch)))->toBe('unavailable')
        ->and(reviewRefusal(fn () => saveGeneration($other, $this->target, $this->batch, ($this->unit)('A'))))->toBe('unavailable')
        ->and(reviewRefusal(fn () => discardGeneration($other, $this->target, $this->batch, ($this->unit)('A'))))->toBe('unavailable')
        ->and(reviewRefusal(fn () => discardGeneration($other, $this->target, $this->batch)))->toBe('unavailable');

    // The Livewire answer gives nothing away either: no text, no owner.
    $this->actingAs($other);
    $answer = Livewire::withQueryParams(['locale' => $this->target])->test(TranslationCenterPage::class)
        ->call('saveAllGenerated', $this->target, $this->batch)
        ->effects['returns'][0];

    expect($answer)->toBe(['saved' => false, 'error' => 'These generated translations are no longer available. Reload the page to see the current state.', 'reason' => 'unavailable'])
        ->and($this->categories['A']->fresh()->name_translations)->toBeNull()
        ->and(translationGenerationOf($this->admin, $this->target)['counts']['ready'])->toBe(4);
});

it('refuses a batch for another language, or an id that is not one', function (Closure $batch, Closure $locale) {
    [, $other] = twoTranslatedLocales();

    expect(reviewRefusal(fn () => saveGeneration($this->admin, $locale($this->target, $other), $batch($this->batch))))->toBe('unavailable');
})->with([
    'another language' => [fn (string $batch) => $batch, fn (string $target, string $other) => $other],
    'not a uuid' => [fn (string $batch) => 'translation-generation:active:1:de', fn (string $target, string $other) => $target],
    'nothing' => [fn (string $batch) => '', fn (string $target, string $other) => $target],
]);
