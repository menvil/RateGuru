<?php

use App\Support\Translations\TranslationCatalogInspector;
use App\Support\Translations\TranslationCatalogIssue;
use Illuminate\Support\Facades\File;

/**
 * The inspector against catalogs built for the case, so each way a language
 * can break the contract is proved to be caught — not just that today's
 * catalogs happen to pass.
 */
function catalogFixture(array $locales, array $json = []): TranslationCatalogInspector
{
    $root = catalogScratchDirectory();

    foreach ($locales as $locale => $catalogs) {
        File::ensureDirectoryExists("{$root}/{$locale}");

        foreach ($catalogs as $catalog => $lines) {
            File::put("{$root}/{$locale}/{$catalog}.php", '<?php return '.var_export($lines, true).';');
        }
    }

    foreach ($json as $locale) {
        File::put("{$root}/{$locale}.json", '{}');
    }

    return new TranslationCatalogInspector($root);
}

afterEach(fn () => removeCatalogScratchDirectory($this));

function referenceCatalogFixture(): array
{
    return [
        'ui' => ['greeting' => 'Hello, :name!', 'nav' => ['home' => 'Home', 'about' => 'About']],
        'mail' => ['subject' => 'Welcome'],
        'admin' => ['title' => 'Dashboard'],
    ];
}

function translatedCatalogFixture(): array
{
    return [
        'ui' => ['greeting' => 'Hallo, :name!', 'nav' => ['home' => 'Start', 'about' => 'Über uns']],
        'mail' => ['subject' => 'Willkommen'],
    ];
}

it('passes a language that keeps the whole contract', function () {
    $report = catalogFixture(['en' => referenceCatalogFixture(), 'zz' => translatedCatalogFixture()])->inspect('zz');

    expect($report->isComplete())->toBeTrue()
        ->and($report->expectedLines)->toBe(4)
        ->and($report->completeLines)->toBe(4)
        ->and($report->percentage())->toBe(100);
});

it('does not ask other languages for the English-only catalogs', function () {
    $inspector = catalogFixture(['en' => referenceCatalogFixture()]);

    expect($inspector->referenceCatalogs())->toBe(['mail', 'ui'])
        ->and($inspector->inspect('en')->isComplete())->toBeTrue()
        ->and($inspector->inspect('en')->expectedLines)->toBe(4);
});

it('catches each way a language breaks the contract', function (array $translated, string $type, int $complete, array $json = []) {
    $report = catalogFixture(['en' => referenceCatalogFixture(), 'zz' => $translated], $json)->inspect('zz');

    expect($report->isComplete())->toBeFalse()
        ->and(array_column(array_map(fn (TranslationCatalogIssue $issue): array => (array) $issue, $report->issues), 'type'))->toContain($type)
        ->and($report->completeLines)->toBe($complete)
        ->and($report->percentage())->toBe(intdiv($complete * 100, 4));
})->with([
    'a missing catalog' => [['ui' => translatedCatalogFixture()['ui']], TranslationCatalogIssue::MISSING_CATALOG, 3],
    'a line set to null' => [array_replace_recursive(translatedCatalogFixture(), ['ui' => ['nav' => ['about' => null]]]), TranslationCatalogIssue::INVALID_LINE, 3],
    'a key that is gone' => [['ui' => ['greeting' => 'Hallo, :name!', 'nav' => ['home' => 'Start']], 'mail' => ['subject' => 'Willkommen']], TranslationCatalogIssue::MISSING_KEY, 3],
    'an extra key' => [array_replace_recursive(translatedCatalogFixture(), ['mail' => ['footer' => 'Tschüss']]), TranslationCatalogIssue::EXTRA_KEY, 4],
    'a blank line' => [array_replace_recursive(translatedCatalogFixture(), ['mail' => ['subject' => '   ']]), TranslationCatalogIssue::INVALID_LINE, 3],
    'a line that is not text' => [array_replace_recursive(translatedCatalogFixture(), ['mail' => ['subject' => 42]]), TranslationCatalogIssue::INVALID_LINE, 3],
    'a dropped placeholder' => [array_replace_recursive(translatedCatalogFixture(), ['ui' => ['greeting' => 'Hallo!']]), TranslationCatalogIssue::PLACEHOLDER_MISMATCH, 3],
    'a catalog copied from English' => [['ui' => translatedCatalogFixture()['ui'], 'mail' => ['subject' => 'Welcome']], TranslationCatalogIssue::COPIED_CATALOG, 3],
    'a catalog English does not have' => [[...translatedCatalogFixture(), 'extra' => ['x' => 'y']], TranslationCatalogIssue::EXTRA_CATALOG, 4],
    'a translated English-only catalog' => [[...translatedCatalogFixture(), 'admin' => ['title' => 'Übersicht']], TranslationCatalogIssue::ENGLISH_ONLY_CATALOG, 4],
    'a JSON catalog' => [translatedCatalogFixture(), TranslationCatalogIssue::JSON_CATALOG, 4, ['zz']],
]);

it('reports a declared language with no catalogs at all as untranslated', function () {
    $report = catalogFixture(['en' => referenceCatalogFixture()])->inspect('zz');

    expect($report->isComplete())->toBeFalse()
        ->and($report->percentage())->toBe(0)
        ->and(array_map(fn (TranslationCatalogIssue $issue): string => $issue->message, $report->issues))
        ->toBe(['zz/mail.php is missing', 'zz/ui.php is missing']);
});

it('holds English to the same rule for its own lines', function () {
    $reference = referenceCatalogFixture();
    $reference['admin']['title'] = '';

    $report = catalogFixture(['en' => $reference])->inspect('en');

    expect($report->isComplete())->toBeFalse()
        ->and($report->issuesOfType(TranslationCatalogIssue::INVALID_LINE))->toHaveCount(1)
        // The admin catalog is checked, but is not part of what a language owes.
        ->and($report->percentage())->toBe(100);
});
