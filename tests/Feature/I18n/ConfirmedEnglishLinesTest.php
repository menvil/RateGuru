<?php

use App\Support\Translations\ConfirmedEnglishLines;
use App\Support\Translations\TranslationCatalogInspector;
use Illuminate\Support\Facades\File;

/*
 * An English line of the interface reworded under the same key leaves every other language saying the old thing,
 * and the parity checks still pass. The English each line's translations were made from is recorded in the
 * repository, so a reworded line is caught until its translations are updated and the English confirmed again.
 */

afterEach(fn () => removeCatalogScratchDirectory($this));

/** Writes a scratch English catalog of these lines, and the English-only admin catalog beside it. */
function scratchEnglishCatalog(string $root, array $lines): void
{
    File::ensureDirectoryExists("{$root}/en");
    File::put("{$root}/en/ui.php", '<?php return '.var_export($lines, true).';');
    File::put("{$root}/en/admin.php", "<?php return ['only' => 'English only'];");
}

it('finds every English line of the repository as its translations were last confirmed', function () {
    $lines = app(ConfirmedEnglishLines::class);
    $changed = $lines->changed();

    // A missing or broken record would compare with nothing and pass whatever the English says.
    expect($lines->isReadable())->toBeTrue(ConfirmedEnglishLines::RECORD.' is missing or is not a JSON object of hashes: run `php artisan rateguru:translations:confirm-english` and commit it.')
        ->and(count($lines->recorded()))->toBeGreaterThan(100);

    expect($changed)->toBe([], 'The English of these interface lines changed since their translations were made: '
        .implode(', ', $changed).'. Update each in every language, then run `php artisan rateguru:translations:confirm-english` and commit '.ConfirmedEnglishLines::RECORD.'.');
});

it('tells a reworded line apart until it is confirmed, never a new one, and drops a line that is gone', function () {
    $root = catalogScratchDirectory();
    scratchEnglishCatalog($root, ['hello' => 'Hello', 'bye' => 'Bye', 'nested' => ['title' => 'Title']]);
    $lines = new ConfirmedEnglishLines(new TranslationCatalogInspector($root), "{$root}/record/english.json");

    expect($lines->isReadable())->toBeFalse()
        ->and($lines->changed())->toBe([])
        ->and($lines->record())->toBe(['changed' => 0, 'added' => 3, 'removed' => 0])
        ->and($lines->isReadable())->toBeTrue()
        // The English-only catalog has no translations to keep in step.
        ->and(array_keys($lines->recorded()))->toBe(['ui.bye', 'ui.hello', 'ui.nested.title']);

    scratchEnglishCatalog($root, ['hello' => 'Hello there', 'nested' => ['title' => 'Title'], 'new' => 'New']);
    $lines = new ConfirmedEnglishLines(new TranslationCatalogInspector($root), "{$root}/record/english.json");

    expect($lines->changed())->toBe(['ui.hello'])
        ->and($lines->record())->toBe(['changed' => 1, 'added' => 1, 'removed' => 1])
        ->and($lines->changed())->toBe([])
        ->and(array_keys($lines->recorded()))->toBe(['ui.hello', 'ui.nested.title', 'ui.new']);
});

it('confirms the English from the command, saying what it confirmed', function () {
    $root = catalogScratchDirectory();
    scratchEnglishCatalog($root, ['hello' => 'Hello']);
    $lines = new ConfirmedEnglishLines(new TranslationCatalogInspector($root), "{$root}/english.json");
    $lines->record();
    scratchEnglishCatalog($root, ['hello' => 'Hello there']);
    app()->instance(ConfirmedEnglishLines::class, new ConfirmedEnglishLines(new TranslationCatalogInspector($root), "{$root}/english.json"));

    $this->artisan('rateguru:translations:confirm-english')
        ->expectsOutput('Confirmed for every language: ui.hello')
        ->expectsOutputToContain('1 reworded, 0 new, 0 gone')
        ->assertSuccessful();

    // Read afresh: an inspector keeps the English it read.
    expect((new ConfirmedEnglishLines(new TranslationCatalogInspector($root), "{$root}/english.json"))->changed())->toBe([]);
});
