<?php

use App\Models\ProjectSettings;
use App\Support\Locale\LanguageRules;
use App\Support\Locale\LocaleManager;
use App\Support\Settings\ProjectSettingsManager;

/**
 * Writes the settings row as it may be found — including states the
 * application never writes itself, which is what the defensive reads are for.
 */
function storeProjectLocales(mixed $enabled): void
{
    ProjectSettings::query()->delete();
    ProjectSettings::factory()->create(['enabled_locales' => $enabled]);
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

// Default --------------------------------------------------------------------

it('has English as its default, whatever the project stores', function (mixed $stored) {
    storeProjectLocales($stored);

    expect(locales()->default())->toBe('en')
        ->and(locales()->isDefault('en'))->toBeTrue()
        ->and(locales()->isEnabled('en'))->toBeTrue();

    foreach (translatedLocales() as $locale) {
        expect(locales()->isDefault($locale))->toBeFalse();
    }
})->with([
    'never chose' => [null],
    'every language' => fn () => supportedLocales(),
    'English alone' => [['en']],
]);

it('returns the labels and flag each locale is declared with', function () {
    foreach (config('locales.supported') as $locale => $info) {
        expect(locales()->label($locale))->toBe($info['label'])
            ->and(locales()->nativeLabel($locale))->toBe($info['native'])
            ->and(locales()->flag($locale))->toBe($info['flag']);
    }
});

// Enabled --------------------------------------------------------------------

it('offers exactly the languages enabled by default when the project never chose any', function () {
    storeProjectLocales(null);

    expect(locales()->enabledCodes())->toBe(locales()->enabledByDefault())
        ->and(locales()->enabled())->toBe(array_intersect_key(config('locales.supported'), array_flip(locales()->enabledByDefault())));
});

it('reads the languages enabled by default from config, in config order', function () {
    expect(locales()->enabledByDefault())->toBe(array_keys(array_filter(
        config('locales.supported'),
        fn (array $info): bool => $info['enabled_by_default'],
    )));
});

it('keeps a language a release adds as not enabled by default away from a project that never chose', function () {
    // The day a release installs a new language, a project still on NULL
    // must not start offering it.
    [, $added] = twoTranslatedLocales();
    config(["locales.supported.{$added}.enabled_by_default" => false]);
    storeProjectLocales(null);

    expect(locales()->isEnabled($added))->toBeFalse()
        ->and(locales()->isSupported($added))->toBeTrue()
        ->and(locales()->enabledCodes())->toBe(locales()->enabledByDefault())
        ->and(locales()->enabledCodes())->not->toContain($added);
});

it('lets a project choose a language that is not enabled by default', function () {
    [, $added] = twoTranslatedLocales();
    config(["locales.supported.{$added}.enabled_by_default" => false]);
    storeProjectLocales(['en', $added]);

    expect(locales()->isEnabled($added))->toBeTrue();
});

it('offers the default even when nothing is enabled by default', function () {
    foreach (supportedLocales() as $locale) {
        config(["locales.supported.{$locale}.enabled_by_default" => false]);
    }

    storeProjectLocales(null);

    expect(locales()->enabledCodes())->toBe(['en']);
});

it('offers the languages enabled by default on an installation without a settings row', function () {
    ProjectSettings::query()->delete();
    app(ProjectSettingsManager::class)->flush();

    expect(locales()->enabledCodes())->toBe(locales()->enabledByDefault());
});

it('offers only the languages the project enabled', function () {
    [$offered, $withheld] = twoTranslatedLocales();
    storeProjectLocales(['en', $offered]);

    expect(locales()->enabledCodes())->toBe(array_values(array_intersect(supportedLocales(), ['en', $offered])))
        ->and(locales()->isEnabled($offered))->toBeTrue()
        ->and(locales()->isEnabled($withheld))->toBeFalse()
        // Withheld is not uninstalled.
        ->and(locales()->isSupported($withheld))->toBeTrue();
});

it('ignores a stored code that is not installed', function () {
    storeProjectLocales(['en', unsupportedLocale()]);

    expect(locales()->enabledCodes())->toBe(['en'])
        ->and(locales()->isEnabled(unsupportedLocale()))->toBeFalse();
});

it('keeps config order and one entry per language, whatever the row holds', function () {
    $reversed = array_reverse(supportedLocales());
    storeProjectLocales([...$reversed, ...$reversed]);

    expect(locales()->enabledCodes())->toBe(supportedLocales());
});

it('offers the default alone when the row offers nothing usable', function (mixed $stored) {
    storeProjectLocales($stored);

    expect(locales()->enabledCodes())->toBe(['en']);
})->with([
    'only an unknown code' => [[unsupportedLocale()]],
    'an empty list' => [[]],
    'not a list at all' => ['en'],
    'no strings' => [[1, null, true]],
]);

it('offers the default even from an old row that left it out', function () {
    // A row written before English was always offered: the default comes back
    // on read, beside what the row chose.
    [$only] = twoTranslatedLocales();
    storeProjectLocales([$only]);

    expect(locales()->enabledCodes())->toBe(array_values(array_intersect(supportedLocales(), ['en', $only])));
});

it('serves a read value only when it is offered, otherwise the default', function () {
    [$offered, $withheld] = twoTranslatedLocales();
    storeProjectLocales(['en', $offered]);

    expect(locales()->enabledOrDefault($offered))->toBe($offered)
        ->and(locales()->enabledOrDefault($withheld))->toBe('en')
        ->and(locales()->enabledOrDefault(unsupportedLocale()))->toBe('en')
        ->and(locales()->enabledOrDefault(null))->toBe('en');
});

// Browser --------------------------------------------------------------------

it('matches a regional browser language to the installed language', function (string $locale) {
    offerEveryInstalledLocale();

    expect(locales()->fromAcceptLanguage("{$locale}-".strtoupper($locale).",{$locale};q=0.9,en;q=0.8"))->toBe($locale)
        ->and(locales()->fromAcceptLanguage(strtoupper($locale).'_'.strtoupper($locale)))->toBe($locale);
})->with(
    // A language installed as one script or variant of several reaches only its own tags: the cases below.
    array_values(array_diff(translatedLocales(), array_keys(LanguageRules::BROWSER_VARIANTS))),
);

it('reaches a language installed as one variant of several only by its own script or region', function (string $header, ?string $expected) {
    offerEveryInstalledLocaleExcept('en');

    expect(locales()->fromAcceptLanguage($header))->toBe($expected);
})->with([
    'zh' => ['zh', 'zh'],
    'zh-CN' => ['zh-CN', 'zh'],
    'zh-SG' => ['zh-SG', 'zh'],
    'zh-Hans' => ['zh-Hans', 'zh'],
    'zh-Hans-CN' => ['zh-Hans-CN', 'zh'],
    'zh-TW: Traditional, not the installed Simplified' => ['zh-TW', null],
    'zh-HK' => ['zh-HK', null],
    'zh-MO' => ['zh-MO', null],
    'zh-Hant' => ['zh-Hant', null],
    'zh-Hant-TW' => ['zh-Hant-TW', null],
    'sr' => ['sr', 'sr'],
    'sr-RS' => ['sr-RS', 'sr'],
    'sr-Cyrl' => ['sr-Cyrl', 'sr'],
    'sr-Cyrl-RS' => ['sr-Cyrl-RS', 'sr'],
    'sr-Latn: Latin, not the installed Cyrillic' => ['sr-Latn', null],
    'sr-Latn-RS' => ['sr-Latn-RS', null],
    'cnr' => ['cnr', 'cnr'],
    'cnr-ME' => ['cnr-ME', 'cnr'],
    'cnr-Latn' => ['cnr-Latn', 'cnr'],
    'cnr-Latn-ME' => ['cnr-Latn-ME', 'cnr'],
    'cnr-Cyrl-ME: Cyrillic, not the installed Latin' => ['cnr-Cyrl-ME', null],
    'pt' => ['pt', 'pt'],
    'pt-BR' => ['pt-BR', 'pt'],
    'pt-PT: European, not the installed Brazilian' => ['pt-PT', null],
    'nb' => ['nb', 'nb'],
    'nb-NO' => ['nb-NO', 'nb'],
    'no: Norwegian is the installed Bokmål' => ['no', 'nb'],
    'no-NO' => ['no-NO', 'nb'],
    'nn-NO: Nynorsk is not installed' => ['nn-NO', null],
    'nn' => ['nn', null],
]);

it('goes on to the next language when the preferred one is another variant of an installed language', function (string $header, string $expected) {
    offerEveryInstalledLocale();

    expect(locales()->fromAcceptLanguage($header))->toBe($expected);
})->with([
    'Traditional Chinese, then English' => ['zh-TW;q=1.0,en;q=0.8', 'en'],
    'Traditional Chinese by script, then German' => ['zh-Hant-TW,de;q=0.9', 'de'],
    'Latin Serbian, then German' => ['sr-Latn-RS;q=1.0,de;q=0.8', 'de'],
    'Cyrillic Montenegrin, then Serbian Cyrillic' => ['cnr-Cyrl-ME,sr-Cyrl;q=0.7', 'sr'],
    'European Portuguese, then Spanish' => ['pt-PT,es;q=0.5', 'es'],
    'Nynorsk, then Norwegian' => ['nn-NO,no;q=0.6', 'nb'],
]);

it('names only installed languages in its browser rules', function () {
    expect(array_keys(config('locales.supported')))
        ->toContain(...array_keys(LanguageRules::BROWSER_VARIANTS))
        ->toContain(...array_values(LanguageRules::BROWSER_ALIASES));
});

it('follows the browser quality order, not the order of the header', function () {
    [$preferred] = twoTranslatedLocales();

    expect(locales()->fromAcceptLanguage("en;q=0.5,{$preferred};q=0.9"))->toBe($preferred);
});

it('skips a language the project does not offer', function () {
    [$offered, $withheld] = twoTranslatedLocales();
    storeProjectLocales(['en', $offered]);

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
