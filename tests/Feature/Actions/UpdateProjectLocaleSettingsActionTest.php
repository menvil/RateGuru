<?php

use App\Actions\Settings\UpdateProjectLocaleSettingsAction;
use App\Exceptions\Settings\IncompleteLocaleCatalogException;
use App\Models\ProjectSettings;
use App\Support\Locale\LocaleManager;
use App\Support\Settings\ProjectSettingsManager;

function updateProjectLocales(array $enabled): ProjectSettings
{
    return app(UpdateProjectLocaleSettingsAction::class)->handle($enabled);
}

beforeEach(function () {
    ProjectSettings::factory()->create(['site_name' => 'Kept']);
});

afterEach(fn () => removeCatalogScratchDirectory($this));

it('stores the offered languages and nothing else', function () {
    [$offered, $withheld] = twoTranslatedLocales();

    updateProjectLocales(['en', $offered]);

    $settings = ProjectSettings::findOrFail(1);

    expect($settings->enabled_locales)->toBe(array_values(array_intersect(supportedLocales(), ['en', $offered])))
        ->and($settings->site_name)->toBe('Kept')
        ->and(app(LocaleManager::class)->isEnabled($withheld))->toBeFalse();
});

it('takes the offered languages only, never a default', function () {
    // The default is English by system policy: there is no argument that
    // could make another language the default.
    $parameters = (new ReflectionMethod(UpdateProjectLocaleSettingsAction::class, 'handle'))->getParameters();

    expect(array_map(fn (ReflectionParameter $parameter): string => $parameter->getName(), $parameters))->toBe(['enabledLocales']);
});

it('is read back at once, without waiting for the settings cache', function () {
    [$offered] = twoTranslatedLocales();
    app(ProjectSettingsManager::class)->current();

    updateProjectLocales(['en', $offered]);

    expect(app(LocaleManager::class)->enabledCodes())->toBe(array_values(array_intersect(supportedLocales(), ['en', $offered])));
});

it('stores config order and one entry per language, whatever the caller sent', function () {
    $reversed = array_reverse(supportedLocales());

    updateProjectLocales([...$reversed, ...$reversed]);

    expect(ProjectSettings::findOrFail(1)->enabled_locales)->toBe(supportedLocales());
});

it('refuses a language that is not installed, instead of dropping it', function () {
    expect(fn () => updateProjectLocales(['en', unsupportedLocale()]))
        ->toThrow(InvalidArgumentException::class, unsupportedLocale());

    expect(ProjectSettings::findOrFail(1)->enabled_locales)->toBeNull();
});

it('refuses a set without English, instead of adding it back', function (array $enabled) {
    expect(fn () => updateProjectLocales($enabled))->toThrow(InvalidArgumentException::class, '[en]');

    expect(ProjectSettings::findOrFail(1)->enabled_locales)->toBeNull();
})->with([
    'no language at all' => [[]],
    'other languages only' => fn () => translatedLocales(),
]);

it('refuses to newly offer a language whose application catalogs break the contract', function () {
    [$offered, $broken] = twoTranslatedLocales();
    updateProjectLocales(['en', $offered]);
    breakCatalogsOf($broken);

    expect(fn () => updateProjectLocales(['en', $offered, $broken]))
        ->toThrow(IncompleteLocaleCatalogException::class, $broken);

    expect(ProjectSettings::findOrFail(1)->enabled_locales)->toBe(array_values(array_intersect(supportedLocales(), ['en', $offered])));
});

it('leaves an offered language with broken catalogs alone, so the rest can still change', function () {
    [$offered, $broken] = twoTranslatedLocales();
    updateProjectLocales(['en', $offered, $broken]);
    breakCatalogsOf($broken);

    updateProjectLocales(['en', $broken]);

    expect(ProjectSettings::findOrFail(1)->enabled_locales)
        ->toContain($broken)
        ->not->toContain($offered);
});

it('creates the settings row on an installation that has none, from the bootstrap', function () {
    ProjectSettings::query()->delete();
    app(ProjectSettingsManager::class)->flush();

    updateProjectLocales(['en']);

    expect(ProjectSettings::findOrFail(1))
        ->enabled_locales->toBe(['en'])
        ->site_name->toBe(app(ProjectSettingsManager::class)->defaults()['site_name'])
        ->static_pages->toBe(config('static-pages.defaults'));
});
