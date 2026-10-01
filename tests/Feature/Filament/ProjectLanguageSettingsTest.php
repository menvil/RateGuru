<?php

use App\Actions\Settings\SaveProjectSettingsAction;
use App\Filament\Pages\ProjectSettingsPage;
use App\Filament\Resources\Categories\Pages\CreateCategory;
use App\Filament\Resources\RatingGroups\Pages\CreateRatingGroup;
use App\Filament\Resources\Tags\Pages\CreateTag;
use App\Models\ProjectSettings;
use App\Models\User;
use App\Support\Locale\LocaleManager;
use Livewire\Livewire;

/**
 * Admin sees two different sets of languages on purpose. Translation editors
 * list every INSTALLED language, so content can be prepared before a language
 * is offered; which languages are offered, and the default, are set on the
 * Languages page (LanguagesPageTest) and nowhere on Project Settings.
 */
beforeEach(function () {
    ProjectSettings::factory()->create();
    $this->actingAs(User::factory()->admin()->create());
});

function withheldProject(): array
{
    [$offered, $withheld] = twoTranslatedLocales();
    offerLocales(array_values(array_diff(supportedLocales(), [$withheld])), $offered);

    return [$offered, $withheld];
}

it('keeps every installed language in the translation editors when some are not offered', function () {
    [, $withheld] = withheldProject();

    foreach (supportedLocales() as $locale) {
        Livewire::test(ProjectSettingsPage::class)
            ->assertFormFieldExists("site_name_translations.{$locale}")
            ->assertFormFieldExists("static_pages.about.{$locale}.title");
        Livewire::test(CreateCategory::class)->assertFormFieldExists("name_translations.{$locale}");
        Livewire::test(CreateTag::class)->assertFormFieldExists("name_translations.{$locale}");
        Livewire::test(CreateRatingGroup::class)->assertFormFieldExists("label_translations.{$locale}");
    }

    expect(app(LocaleManager::class)->isEnabled($withheld))->toBeFalse();
});

it('no longer sets the default language on the project settings page', function () {
    Livewire::test(ProjectSettingsPage::class)->assertFormFieldDoesNotExist('default_locale');
});

it('keeps the offered languages when the other settings are saved', function () {
    [$offered] = withheldProject();
    $enabled = ProjectSettings::findOrFail(1)->enabled_locales;

    Livewire::test(ProjectSettingsPage::class)
        ->set('data.site_name', 'Renamed')
        ->call('save')
        ->assertHasNoErrors();

    expect(ProjectSettings::findOrFail(1))
        ->site_name->toBe('Renamed')
        ->enabled_locales->toBe($enabled)
        ->default_locale->toBe($offered);
});

it('refuses a default outside the offered languages at the action as well', function () {
    [, $withheld] = withheldProject();

    expect(fn () => app(SaveProjectSettingsAction::class)->handle(['default_locale' => $withheld]))
        ->toThrow(InvalidArgumentException::class, $withheld);
});

it('refuses to take the offered languages together with the other settings', function () {
    // One payload could otherwise replace the set and pass its own default
    // check against the set it is about to replace.
    [$offered, $withheld] = withheldProject();
    $before = ProjectSettings::findOrFail(1)->only(['site_name', 'enabled_locales', 'default_locale']);

    expect(fn () => app(SaveProjectSettingsAction::class)->handle([
        'site_name' => 'Changed',
        'enabled_locales' => [$withheld],
        'default_locale' => $withheld,
    ]))->toThrow(InvalidArgumentException::class, 'UpdateProjectLocaleSettingsAction');

    expect(ProjectSettings::findOrFail(1)->only(['site_name', 'enabled_locales', 'default_locale']))->toBe($before)
        ->and(app(LocaleManager::class)->projectDefault())->toBe($offered);
});
