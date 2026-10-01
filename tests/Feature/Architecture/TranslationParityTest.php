<?php

use App\Support\Translations\TranslationCatalogInspector;
use App\Support\Translations\TranslationCatalogIssue;

/**
 * Adding a language must fail loudly until it is finished.
 *
 * The failure mode this exists for is silence. Laravel's __() returns the key
 * itself when a translation is missing, so a half-translated language ships
 * looking fine in review and reaches users as a mix of their language and raw
 * `ui.notifications.messages.post_approved` strings. Nothing throws, no test
 * fails, and the first report comes from a person, not from CI.
 *
 * So the contract is: English is the reference, and every supported locale must
 * match it exactly — same files, same keys, same placeholders. Declaring a
 * locale in config/locales.php without finishing it turns CI red with the list
 * of what is missing.
 *
 * The contract itself lives in TranslationCatalogInspector, which the Languages
 * page reads on a running server too: there is one implementation of it, and
 * each check below reports its own slice of what that implementation finds.
 *
 * The one exception is the admin panel. It always renders in English
 * (SetAdminLocale), so its catalog exists in English only and is not part of
 * what a language has to translate.
 */
function catalogs(): TranslationCatalogInspector
{
    return app(TranslationCatalogInspector::class);
}

/**
 * The messages of every issue of these types, across these languages.
 *
 * @param  list<string>  $locales
 * @return list<string>
 */
function catalogProblems(array $locales, string ...$types): array
{
    $problems = [];

    foreach ($locales as $locale) {
        foreach (catalogs()->inspect($locale)->issuesOfType(...$types) as $issue) {
            $problems[] = $issue->message;
        }
    }

    return $problems;
}

it('has English as a complete reference', function () {
    expect(catalogs()->referenceCatalogs())->not->toBeEmpty()
        ->and(supportedLocales())->toContain(TranslationCatalogInspector::REFERENCE_LOCALE);

    foreach (TranslationCatalogInspector::ENGLISH_ONLY_CATALOGS as $file) {
        expect(is_file(lang_path("en/{$file}.php")))->toBeTrue("en/{$file}.php is declared English-only but does not exist");
    }
});

it('reports every supported language as completely translated', function (string $locale) {
    $report = catalogs()->inspect($locale);

    expect($report->isComplete())->toBeTrue(implode("\n", array_map(fn (TranslationCatalogIssue $issue): string => $issue->message, $report->issues)))
        ->and($report->percentage())->toBe(100)
        ->and($report->expectedLines)->toBeGreaterThan(0)
        ->and($report->completeLines)->toBe($report->expectedLines);
})->with(supportedLocales());

it('ships exactly the reference files for every supported language', function () {
    $problems = catalogProblems(
        translatedLocales(),
        TranslationCatalogIssue::MISSING_CATALOG,
        TranslationCatalogIssue::ENGLISH_ONLY_CATALOG,
        TranslationCatalogIssue::EXTRA_CATALOG,
    );

    expect($problems)->toBe([], "translation catalog drift:\n".implode("\n", $problems));
});

it('turns red for a language declared without its catalogs', function () {
    // What happens the day a language is added to config/locales.php before it
    // is translated: every catalog it owes is named, not just a count.
    $declared = unsupportedLocale();
    $report = catalogs()->inspect($declared);

    expect(array_map(fn (TranslationCatalogIssue $issue): string => $issue->message, $report->issues))
        ->toBe(array_map(fn (string $file): string => "{$declared}/{$file}.php is missing", catalogs()->referenceCatalogs()))
        ->and($report->isComplete())->toBeFalse()
        ->and($report->percentage())->toBe(0);
});

it('ships no JSON catalogs', function () {
    // lang/{locale}.json is keyed by the English sentence itself, so none of
    // the checks here can reach it: rewording the sentence in a view silently
    // orphans every translation of it, and nothing fails.
    $json = catalogs()->jsonCatalogs();

    expect($json)->toBe([], 'JSON catalogs found: '.implode(', ', $json));
});

it('translates every key, with nothing extra', function () {
    // Extra keys are a problem too: they are usually a rename that was applied
    // to one language and forgotten in another, and they are invisible until
    // the old key stops being used.
    $problems = catalogProblems(translatedLocales(), TranslationCatalogIssue::MISSING_KEY, TranslationCatalogIssue::EXTRA_KEY);

    expect($problems)->toBe([], "translation key drift:\n".implode("\n", $problems));
});

it('never ships an English catalog under another language', function () {
    // A file copied from English and never translated passes every check
    // above — same keys, same placeholders, nothing blank — while every reader
    // of that language gets English. Individual lines may match English (a
    // brand name, "Email"); a whole catalog that matches line for line is a
    // copy.
    $problems = catalogProblems(translatedLocales(), TranslationCatalogIssue::COPIED_CATALOG);

    expect($problems)->toBe([], "untranslated copies:\n".implode("\n", $problems));
});

it('never ships a blank or non-text line', function () {
    // A blank string is worse than a missing key: __() returns it happily, so
    // the UI renders an empty label instead of anything a reader can report.
    // A null, number or boolean is the same failure in a different spelling.
    $problems = catalogProblems(supportedLocales(), TranslationCatalogIssue::INVALID_LINE);

    expect($problems)->toBe([], "empty translations:\n".implode("\n", $problems));
});

it('keeps every placeholder a line declares', function () {
    // The quiet one. Dropping :username from a translation does not fail
    // anything — it just renders a sentence with the name silently gone.
    $problems = catalogProblems(translatedLocales(), TranslationCatalogIssue::PLACEHOLDER_MISMATCH);

    expect($problems)->toBe([], "placeholder drift:\n".implode("\n", $problems));
});

it('translates every message an in-app notification can carry', function () {
    // The renderer builds `ui.notifications.messages.<type>` from the payload,
    // so a notification type without a line renders its own key at the reader.
    $types = collect(glob(app_path('Notifications/*.php')) ?: [])
        ->map(fn (string $path): string => (string) file_get_contents($path))
        ->flatMap(function (string $source): array {
            preg_match_all("/'message_key' => 'ui\.notifications\.messages\.([a-z_]+)'/", $source, $matches);

            return $matches[1];
        })
        ->unique()
        ->values();

    expect($types)->not->toBeEmpty();

    $problems = [];

    foreach (supportedLocales() as $locale) {
        $lines = catalogs()->lines($locale, 'ui');

        foreach ($types as $type) {
            if (! isset($lines["notifications.messages.{$type}"])) {
                $problems[] = "{$locale}/ui.php is missing [notifications.messages.{$type}]";
            }
        }
    }

    expect($problems)->toBe([], "untranslated notifications:\n".implode("\n", $problems));
});
