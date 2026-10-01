<?php

use App\Actions\Settings\UpdateProjectLocaleSettingsAction;
use App\Exceptions\Settings\IncompleteLocaleCatalogException;
use App\Models\ProjectSettings;
use App\Support\Locale\LocaleManager;
use App\Support\Settings\ProjectSettingsManager;

function updateProjectLocales(array $enabled, string $default): ProjectSettings
{
    return app(UpdateProjectLocaleSettingsAction::class)->handle($enabled, $default);
}

beforeEach(function () {
    ProjectSettings::factory()->create(['site_name' => 'Kept']);
});

afterEach(fn () => removeCatalogScratchDirectory($this));

it('stores the offered languages and the default together', function () {
    [$offered, $withheld] = twoTranslatedLocales();

    updateProjectLocales(['en', $offered], $offered);

    $settings = ProjectSettings::findOrFail(1);

    expect($settings->enabled_locales)->toBe(array_values(array_intersect(supportedLocales(), ['en', $offered])))
        ->and($settings->default_locale)->toBe($offered)
        ->and($settings->site_name)->toBe('Kept')
        ->and(app(LocaleManager::class)->isEnabled($withheld))->toBeFalse();
});

it('is read back at once, without waiting for the settings cache', function () {
    [$offered] = twoTranslatedLocales();
    app(ProjectSettingsManager::class)->current();

    updateProjectLocales([$offered], $offered);

    expect(app(LocaleManager::class)->enabledCodes())->toBe([$offered])
        ->and(app(LocaleManager::class)->projectDefault())->toBe($offered);
});

it('stores config order and one entry per language, whatever the caller sent', function () {
    $reversed = array_reverse(supportedLocales());

    updateProjectLocales([...$reversed, ...$reversed], 'en');

    expect(ProjectSettings::findOrFail(1)->enabled_locales)->toBe(supportedLocales());
});

it('refuses a language that is not installed, instead of dropping it', function () {
    expect(fn () => updateProjectLocales(['en', unsupportedLocale()], 'en'))
        ->toThrow(InvalidArgumentException::class, unsupportedLocale());

    expect(ProjectSettings::findOrFail(1)->enabled_locales)->toBeNull();
});

it('refuses to offer no language at all', function () {
    expect(fn () => updateProjectLocales([], 'en'))->toThrow(InvalidArgumentException::class);
});

it('refuses a default the project would not offer', function (string $default) {
    [$offered] = twoTranslatedLocales();

    expect(fn () => updateProjectLocales(['en', $offered], $default))
        ->toThrow(InvalidArgumentException::class, $default);

    expect(ProjectSettings::findOrFail(1)->default_locale)->toBe('en');
})->with([
    'installed but not offered' => fn () => twoTranslatedLocales()[1],
    'not installed' => fn () => unsupportedLocale(),
]);

it('refuses to newly offer a language whose application catalogs break the contract', function () {
    [$offered, $broken] = twoTranslatedLocales();
    updateProjectLocales(['en', $offered], 'en');
    breakCatalogsOf($broken);

    expect(fn () => updateProjectLocales(['en', $offered, $broken], 'en'))
        ->toThrow(IncompleteLocaleCatalogException::class, $broken);

    expect(ProjectSettings::findOrFail(1)->enabled_locales)->toBe(array_values(array_intersect(supportedLocales(), ['en', $offered])));
});

it('leaves an offered language with broken catalogs alone, so the rest can still change', function () {
    [$offered, $broken] = twoTranslatedLocales();
    updateProjectLocales(['en', $offered, $broken], 'en');
    breakCatalogsOf($broken);

    updateProjectLocales(['en', $offered, $broken], $offered);

    expect(ProjectSettings::findOrFail(1))
        ->default_locale->toBe($offered)
        ->enabled_locales->toContain($broken);
});

it('lets the technical fallback go unoffered', function () {
    [$only] = twoTranslatedLocales();

    updateProjectLocales([$only], $only);

    expect(app(LocaleManager::class)->isEnabled(config('locales.fallback')))->toBeFalse()
        ->and(app(LocaleManager::class)->projectDefault())->toBe($only);
});

it('creates the settings row on an installation that has none', function () {
    ProjectSettings::query()->delete();
    app(ProjectSettingsManager::class)->flush();

    updateProjectLocales(['en'], 'en');

    expect(ProjectSettings::findOrFail(1))
        ->enabled_locales->toBe(['en'])
        ->default_locale->toBe('en')
        ->site_name->toBe(app(ProjectSettingsManager::class)->defaults()['site_name']);
});
