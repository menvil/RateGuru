<?php

use App\Actions\Settings\SaveProjectSettingsAction;
use App\Filament\Pages\ProjectSettingsPage;
use App\Models\ProjectSettings;
use App\Models\User;
use Livewire\Livewire;

/**
 * Which languages the project offers is set on the Languages page
 * (LanguagesPageTest) and nowhere on Project Settings. The default is English
 * by system policy and set nowhere. Translations are edited in Translation
 * Center, for every installed language but English, the reference, offered
 * or not.
 */
beforeEach(function () {
    ProjectSettings::factory()->create();
    $this->actingAs(User::factory()->admin()->create());
});

function withheldProject(): array
{
    [$offered, $withheld] = twoTranslatedLocales();
    offerEveryInstalledLocaleExcept($withheld);

    return [$offered, $withheld];
}

it('no longer sets the default language on the project settings page', function () {
    Livewire::test(ProjectSettingsPage::class)->assertFormFieldDoesNotExist('default_locale');
});

it('keeps the offered languages when the other settings are saved', function () {
    withheldProject();
    $enabled = ProjectSettings::findOrFail(1)->enabled_locales;

    Livewire::test(ProjectSettingsPage::class)
        ->set('data.site_name', 'Renamed')
        ->call('save')
        ->assertHasNoErrors();

    expect(ProjectSettings::findOrFail(1))
        ->site_name->toBe('Renamed')
        ->enabled_locales->toBe($enabled);
});

it('refuses to take the offered languages together with the other settings', function () {
    // Only UpdateProjectLocaleSettingsAction writes them: it keeps English
    // among them and refuses a language with broken catalogs.
    [, $withheld] = withheldProject();
    $before = ProjectSettings::findOrFail(1)->only(['site_name', 'enabled_locales']);

    expect(fn () => app(SaveProjectSettingsAction::class)->handle([
        'site_name' => 'Changed',
        'enabled_locales' => [$withheld],
    ]))->toThrow(InvalidArgumentException::class, 'UpdateProjectLocaleSettingsAction');

    expect(ProjectSettings::findOrFail(1)->only(['site_name', 'enabled_locales']))->toBe($before);
});
