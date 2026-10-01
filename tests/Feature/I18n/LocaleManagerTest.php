<?php

use App\Models\ProjectSettings;
use App\Support\Locale\LocaleManager;
use App\Support\Settings\ProjectSettingsManager;

/**
 * Writes the settings row as it may be found — including states the
 * application never writes itself, which is what the defensive reads are for.
 */
function storeProjectLocales(mixed $enabled, string $default): void
{
    ProjectSettings::query()->delete();
    ProjectSettings::factory()->create(['enabled_locales' => $enabled, 'default_locale' => $default]);
    app(ProjectSettingsManager::class)->flush();
}

function locales(): LocaleManager
{
    return app(LocaleManager::class);
}

// Installed ------------------------------------------------------------------

it('treats every configured language as installed', function () {
    expect(locales()->supported())->toBe(config('locales.supported'));

    foreach (supportedLocales() as $locale) {
        expect(locales()->isSupported($locale))->toBeTrue();
    }

    expect(locales()->isSupported(unsupportedLocale()))->toBeFalse();
});

it('keeps the technical fallback installed', function () {
    expect(locales()->fallback())->toBe(config('locales.fallback'))
        ->and(locales()->isSupported(locales()->fallback()))->toBeTrue();
});

it('returns the labels and flag each locale is declared with', function () {
    foreach (config('locales.supported') as $locale => $info) {
        expect(locales()->label($locale))->toBe($info['label'])
            ->and(locales()->nativeLabel($locale))->toBe($info['native'])
            ->and(locales()->flag($locale))->toBe($info['flag']);
    }
});

// Enabled --------------------------------------------------------------------

it('offers the languages enabled by default when the project never chose any', function () {
    storeProjectLocales(null, 'en');

    expect(locales()->enabled())->toBe(config('locales.supported'))
        ->and(locales()->enabledCodes())->toBe(supportedLocales())
        ->and(locales()->enabledByDefault())->toBe(supportedLocales());
});

it('keeps a language a release adds as not enabled by default away from a project that never chose', function () {
    // The day a release installs a new language, a project still on NULL
    // must not start offering it.
    [, $added] = twoTranslatedLocales();
    config(["locales.supported.{$added}.enabled_by_default" => false]);
    storeProjectLocales(null, 'en');

    expect(locales()->isEnabled($added))->toBeFalse()
        ->and(locales()->isSupported($added))->toBeTrue()
        ->and(locales()->enabledCodes())->toBe(array_values(array_diff(supportedLocales(), [$added])));
});

it('lets a project choose a language that is not enabled by default', function () {
    [, $added] = twoTranslatedLocales();
    config(["locales.supported.{$added}.enabled_by_default" => false]);
    storeProjectLocales(['en', $added], 'en');

    expect(locales()->isEnabled($added))->toBeTrue();
});

it('falls back to the technical locale when nothing is enabled by default', function () {
    foreach (supportedLocales() as $locale) {
        config(["locales.supported.{$locale}.enabled_by_default" => false]);
    }

    storeProjectLocales(null, 'en');

    expect(locales()->enabledCodes())->toBe([locales()->fallback()]);
});

it('offers every installed language on an installation without a settings row', function () {
    ProjectSettings::query()->delete();
    app(ProjectSettingsManager::class)->flush();

    expect(locales()->enabledCodes())->toBe(supportedLocales());
});

it('offers only the languages the project enabled', function () {
    [$offered, $withheld] = twoTranslatedLocales();
    storeProjectLocales(['en', $offered], 'en');

    expect(locales()->enabledCodes())->toBe(array_values(array_intersect(supportedLocales(), ['en', $offered])))
        ->and(locales()->isEnabled($offered))->toBeTrue()
        ->and(locales()->isEnabled($withheld))->toBeFalse()
        // Withheld is not uninstalled.
        ->and(locales()->isSupported($withheld))->toBeTrue();
});

it('ignores a stored code that is not installed', function () {
    storeProjectLocales(['en', unsupportedLocale()], 'en');

    expect(locales()->enabledCodes())->toBe(['en'])
        ->and(locales()->isEnabled(unsupportedLocale()))->toBeFalse();
});

it('keeps config order and one entry per language, whatever the row holds', function () {
    $reversed = array_reverse(supportedLocales());
    storeProjectLocales([...$reversed, ...$reversed], 'en');

    expect(locales()->enabledCodes())->toBe(supportedLocales());
});

it('falls back to the technical locale when the row offers nothing usable', function (mixed $stored) {
    storeProjectLocales($stored, 'en');

    expect(locales()->enabledCodes())->toBe([locales()->fallback()]);
})->with([
    'only an unknown code' => [[unsupportedLocale()]],
    'an empty list' => [[]],
    'not a list at all' => ['en'],
    'no strings' => [[1, null, true]],
]);

// Project default ------------------------------------------------------------

it('uses the project default when the project offers it', function () {
    [$default] = twoTranslatedLocales();
    storeProjectLocales(null, $default);

    expect(locales()->projectDefault())->toBe($default);
});

it('replaces a default the project does not offer with one it does', function (string $default) {
    [$offered] = twoTranslatedLocales();
    storeProjectLocales(['en', $offered], $default);

    expect(locales()->projectDefault())->toBe('en')
        ->and(locales()->isEnabled(locales()->projectDefault()))->toBeTrue();
})->with([
    'installed but withheld' => fn () => twoTranslatedLocales()[1],
    'not installed' => fn () => unsupportedLocale(),
]);

it('never answers with a withheld technical fallback', function () {
    // English stays installed as the emergency catalog while this project
    // offers only another language — a normal configuration.
    [$only] = twoTranslatedLocales();
    storeProjectLocales([$only], 'en');

    expect(locales()->isEnabled('en'))->toBeFalse()
        ->and(locales()->projectDefault())->toBe($only);
});

it('serves a read value only when it is offered', function () {
    [$offered, $withheld] = twoTranslatedLocales();
    storeProjectLocales(['en', $offered], $offered);

    expect(locales()->enabledOrDefault($offered))->toBe($offered)
        ->and(locales()->enabledOrDefault($withheld))->toBe($offered)
        ->and(locales()->enabledOrDefault(unsupportedLocale()))->toBe($offered)
        ->and(locales()->enabledOrDefault(null))->toBe($offered);
});

// Browser --------------------------------------------------------------------

it('matches a regional browser language to the installed language', function (string $locale) {
    expect(locales()->fromAcceptLanguage("{$locale}-".strtoupper($locale).",{$locale};q=0.9,en;q=0.8"))->toBe($locale)
        ->and(locales()->fromAcceptLanguage(strtoupper($locale).'_'.strtoupper($locale)))->toBe($locale);
})->with(translatedLocales());

it('follows the browser quality order, not the order of the header', function () {
    [$preferred] = twoTranslatedLocales();

    expect(locales()->fromAcceptLanguage("en;q=0.5,{$preferred};q=0.9"))->toBe($preferred);
});

it('skips a language the project does not offer', function () {
    [$offered, $withheld] = twoTranslatedLocales();
    storeProjectLocales(['en', $offered], 'en');

    expect(locales()->fromAcceptLanguage("{$withheld}-".strtoupper($withheld).",{$withheld};q=0.9,{$offered};q=0.8"))->toBe($offered);
});

it('answers nothing, rather than the first offered language, when the browser asks for none of them', function (string $header) {
    expect(locales()->fromAcceptLanguage($header))->toBeNull();
})->with([
    'an unknown language' => fn () => unsupportedLocale().'-XX,'.unsupportedLocale().';q=0.9',
    'the wildcard' => '*',
    'refused with q=0' => 'en;q=0',
    'an empty header' => '',
]);

it('ignores a missing header', function () {
    expect(locales()->fromAcceptLanguage(null))->toBeNull();
});
