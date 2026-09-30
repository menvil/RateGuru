<?php

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
 * The one exception is the admin panel. It always renders in English
 * (SetAdminLocale), so its catalog exists in English only and is not part of
 * what a language has to translate.
 */

/**
 * Catalogs that exist only in English because nothing ever reads them in
 * another language.
 *
 * @return list<string>
 */
function englishOnlyCatalogs(): array
{
    return ['admin'];
}

/** @return array<string, mixed> */
function flattenTranslations(array $lines, string $prefix = ''): array
{
    $flat = [];

    foreach ($lines as $key => $value) {
        $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

        if (is_array($value)) {
            $flat += flattenTranslations($value, $path);

            continue;
        }

        $flat[$path] = $value;
    }

    return $flat;
}

/** @return array<string, mixed> */
function translationsFor(string $locale, string $file): array
{
    $path = lang_path("{$locale}/{$file}.php");

    return is_file($path) ? flattenTranslations((array) require $path) : [];
}

/** @return list<string> */
function catalogsIn(string $locale): array
{
    return collect(glob(lang_path("{$locale}/*.php")) ?: [])
        ->map(fn (string $path): string => basename($path, '.php'))
        ->sort()
        ->values()
        ->all();
}

/**
 * The catalogs every supported language has to translate.
 *
 * @return list<string>
 */
function referenceFiles(): array
{
    return array_values(array_diff(catalogsIn('en'), englishOnlyCatalogs()));
}

/**
 * What is wrong with the set of catalog files each of these languages ships.
 *
 * @param  list<string>  $locales
 * @return list<string>
 */
function catalogDrift(array $locales): array
{
    $problems = [];

    foreach ($locales as $locale) {
        $expected = referenceFiles();
        $actual = catalogsIn($locale);

        foreach (array_diff($expected, $actual) as $file) {
            $problems[] = "{$locale}/{$file}.php is missing";
        }

        foreach (array_intersect($actual, englishOnlyCatalogs()) as $file) {
            $problems[] = "{$locale}/{$file}.php translates a catalog that is only ever read in English";
        }

        // Both directions, for the same reason extra KEYS are a problem: a
        // catalog English does not have is usually a rename applied to one
        // language only. Worse, every check below iterates the English files,
        // so an orphan is never opened at all — its keys, blanks and
        // placeholders are unverified, and it looks translated to anyone
        // reading the directory.
        foreach (array_diff($actual, $expected, englishOnlyCatalogs()) as $file) {
            $problems[] = "{$locale}/{$file}.php has no English reference, so nothing checks it";
        }
    }

    return $problems;
}

/** The :placeholders a line declares, which must survive translation. */
function placeholdersIn(string $line): array
{
    preg_match_all('/:([a-zA-Z_][a-zA-Z0-9_]*)/', $line, $matches);

    return collect($matches[1])->unique()->sort()->values()->all();
}

it('has English as a complete reference', function () {
    expect(referenceFiles())->not->toBeEmpty()
        ->and(supportedLocales())->toContain('en');

    foreach (englishOnlyCatalogs() as $file) {
        expect(is_file(lang_path("en/{$file}.php")))->toBeTrue("en/{$file}.php is declared English-only but does not exist");
    }
});

it('ships exactly the reference files for every supported language', function () {
    $problems = catalogDrift(translatedLocales());

    expect($problems)->toBe([], "translation catalog drift:\n".implode("\n", $problems));
});

it('turns red for a language declared without its catalogs', function () {
    // What happens the day a language is added to config/locales.php before it
    // is translated: every catalog it owes is named, not just a count.
    $declared = unsupportedLocale();

    expect(catalogDrift([$declared]))
        ->toBe(array_map(fn (string $file): string => "{$declared}/{$file}.php is missing", referenceFiles()));
});

it('ships no JSON catalogs', function () {
    // lang/{locale}.json is keyed by the English sentence itself, so none of
    // the checks here can reach it: rewording the sentence in a view silently
    // orphans every translation of it, and nothing fails.
    $json = collect(glob(lang_path('*.json')) ?: [])
        ->map(fn (string $path): string => basename($path))
        ->all();

    expect($json)->toBe([], 'JSON catalogs found: '.implode(', ', $json));
});

it('translates every key, with nothing extra', function () {
    $problems = [];

    foreach (referenceFiles() as $file) {
        $reference = translationsFor('en', $file);

        foreach (translatedLocales() as $locale) {
            $translated = translationsFor($locale, $file);

            foreach (array_diff(array_keys($reference), array_keys($translated)) as $key) {
                $problems[] = "{$locale}/{$file}.php is missing [{$key}]";
            }

            // Extra keys are a problem too: they are usually a rename that was
            // applied to one language and forgotten in another, and they are
            // invisible until the old key stops being used.
            foreach (array_diff(array_keys($translated), array_keys($reference)) as $key) {
                $problems[] = "{$locale}/{$file}.php has [{$key}], which English does not";
            }
        }
    }

    expect($problems)->toBe([], "translation key drift:\n".implode("\n", $problems));
});

it('never ships an English catalog under another language', function () {
    // A file copied from English and never translated passes every check
    // above — same keys, same placeholders, nothing blank — while every reader
    // of that language gets English. Individual lines may match English (a
    // brand name, "Email"); a whole catalog that matches line for line is a
    // copy.
    $problems = [];

    foreach (referenceFiles() as $file) {
        $reference = translationsFor('en', $file);

        foreach (translatedLocales() as $locale) {
            $translated = translationsFor($locale, $file);

            if ($translated !== [] && $translated == $reference) {
                $problems[] = "{$locale}/{$file}.php is English, line for line";
            }
        }
    }

    expect($problems)->toBe([], "untranslated copies:\n".implode("\n", $problems));
});

it('never ships a blank or non-text line', function () {
    // A blank string is worse than a missing key: __() returns it happily, so
    // the UI renders an empty label instead of anything a reader can report.
    // A null, number or boolean is the same failure in a different spelling.
    $problems = [];

    foreach (supportedLocales() as $locale) {
        $files = $locale === 'en' ? catalogsIn('en') : referenceFiles();

        foreach ($files as $file) {
            foreach (translationsFor($locale, $file) as $key => $value) {
                if (! is_string($value)) {
                    $problems[] = "{$locale}/{$file}.php has a ".get_debug_type($value)." at [{$key}]";
                } elseif (trim($value) === '') {
                    $problems[] = "{$locale}/{$file}.php has an empty [{$key}]";
                }
            }
        }
    }

    expect($problems)->toBe([], "empty translations:\n".implode("\n", $problems));
});

it('keeps every placeholder a line declares', function () {
    // The quiet one. Dropping :username from a translation does not fail
    // anything — it just renders a sentence with the name silently gone.
    $problems = [];

    foreach (referenceFiles() as $file) {
        $reference = translationsFor('en', $file);

        foreach (translatedLocales() as $locale) {
            $translated = translationsFor($locale, $file);

            foreach ($reference as $key => $value) {
                if (! is_string($value) || ! isset($translated[$key]) || ! is_string($translated[$key])) {
                    continue;
                }

                $expected = placeholdersIn($value);
                $actual = placeholdersIn($translated[$key]);

                if ($expected !== $actual) {
                    $problems[] = sprintf(
                        '%s/%s.php [%s] declares (%s), English declares (%s)',
                        $locale, $file, $key,
                        implode(', ', $actual) ?: 'none',
                        implode(', ', $expected) ?: 'none',
                    );
                }
            }
        }
    }

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
        $lines = translationsFor($locale, 'ui');

        foreach ($types as $type) {
            if (! isset($lines["notifications.messages.{$type}"])) {
                $problems[] = "{$locale}/ui.php is missing [notifications.messages.{$type}]";
            }
        }
    }

    expect($problems)->toBe([], "untranslated notifications:\n".implode("\n", $problems));
});
