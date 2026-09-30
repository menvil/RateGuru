<?php

use App\Support\Locale\LocaleManager;
use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\Finder;

/**
 * config/locales.php is the only place that lists the languages we offer.
 *
 * A copy of the list anywhere else — the codes written out in a dataset, a
 * loop, a toHaveKeys() — is a place that silently ignores the next language:
 * it keeps passing while the new language is never exercised, and nobody is
 * told to update it. Everything that means "every language" reads supportedLocales()
 * or config('locales.supported') instead.
 *
 * A single code is fine — a test about Russian plurals is about Russian. What
 * this refuses is two or more supported codes written out as a list.
 */
it('reads the supported languages from config/locales.php alone', function () {
    $configured = array_keys(config('locales.supported'));

    expect(supportedLocales())->toBe($configured)
        ->and(array_keys(app(LocaleManager::class)->supported()))->toBe($configured)
        ->and(translatedLocales())->toBe(array_values(array_diff($configured, ['en'])))
        ->and($configured)->not->toContain(unsupportedLocale());
});

it('never spells out the list of supported languages', function () {
    $codes = implode('|', array_map(preg_quote(...), supportedLocales()));
    $pattern = "/\\[\\s*(['\"])(?:{$codes})\\1(?:\\s*,\\s*(['\"])(?:{$codes})\\2)+\\s*,?\\s*\\]/";

    $files = Finder::create()
        ->files()
        ->in([base_path('tests'), app_path(), config_path(), resource_path('views'), base_path('routes'), database_path()])
        ->name(['*.php', '*.blade.php'])
        ->notPath('Browser/Screenshots');

    $problems = [];

    foreach ($files as $file) {
        if (preg_match_all($pattern, File::get($file->getPathname()), $matches)) {
            $relative = str_replace(base_path().'/', '', $file->getPathname());

            foreach ($matches[0] as $list) {
                $problems[] = "{$relative}: {$list}";
            }
        }
    }

    expect($problems)->toBe([], "supported languages spelled out instead of read from config/locales.php:\n".implode("\n", $problems));
});
