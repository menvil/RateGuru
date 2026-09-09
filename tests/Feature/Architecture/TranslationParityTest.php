<?php

use App\Support\Locale\LocaleManager;

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
 */

/** @return list<string> */
function supportedLocales(): array
{
    return array_keys(app(LocaleManager::class)->supported());
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
function referenceFiles(): array
{
    return collect(glob(lang_path('en/*.php')) ?: [])
        ->map(fn (string $path): string => basename($path, '.php'))
        ->sort()
        ->values()
        ->all();
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
});

it('ships exactly the reference files for every supported language', function () {
    $problems = [];

    foreach (supportedLocales() as $locale) {
        $expected = referenceFiles();

        $actual = collect(glob(lang_path("{$locale}/*.php")) ?: [])
            ->map(fn (string $path): string => basename($path, '.php'))
            ->sort()
            ->values()
            ->all();

        foreach (array_diff($expected, $actual) as $file) {
            $problems[] = "{$locale}/{$file}.php is missing";
        }

        // Both directions, for the same reason extra KEYS are a problem: a
        // catalog English does not have is usually a rename applied to one
        // language only. Worse, every check below iterates the English files,
        // so an orphan is never opened at all — its keys, blanks and
        // placeholders are unverified, and it looks translated to anyone
        // reading the directory.
        foreach (array_diff($actual, $expected) as $file) {
            $problems[] = "{$locale}/{$file}.php has no English reference, so nothing checks it";
        }
    }

    expect($problems)->toBe([], "translation catalog drift:\n".implode("\n", $problems));
});

it('translates every key, with nothing extra', function () {
    $problems = [];

    foreach (referenceFiles() as $file) {
        $reference = translationsFor('en', $file);

        foreach (supportedLocales() as $locale) {
            if ($locale === 'en') {
                continue;
            }

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

it('never ships a blank translation', function () {
    // A blank string is worse than a missing key: __() returns it happily, so
    // the UI renders an empty label instead of anything a reader can report.
    $problems = [];

    foreach (referenceFiles() as $file) {
        foreach (supportedLocales() as $locale) {
            foreach (translationsFor($locale, $file) as $key => $value) {
                if (is_string($value) && trim($value) === '') {
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

        foreach (supportedLocales() as $locale) {
            if ($locale === 'en') {
                continue;
            }

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
