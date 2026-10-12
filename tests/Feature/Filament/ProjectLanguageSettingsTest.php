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
 * is offered; which languages are offered is set on the Languages page
 * (LanguagesPageTest) and nowhere on Project Settings. The default is English
 * by system policy and set nowhere.
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

it('keeps every installed language in the translation editors when some are not offered', function () {
    [, $withheld] = withheldProject();

    // Each editor is drawn once and asked for every language: the fields are
    // all in that one form, so drawing it again per language proves nothing.
    $settings = Livewire::test(ProjectSettingsPage::class);
    $category = Livewire::test(CreateCategory::class);
    $tag = Livewire::test(CreateTag::class);
    $ratingGroup = Livewire::test(CreateRatingGroup::class);

    foreach (supportedLocales() as $locale) {
        $settings->assertFormFieldExists("site_name_translations.{$locale}")
            ->assertFormFieldExists("static_pages.about.{$locale}.title");
        $category->assertFormFieldExists("name_translations.{$locale}");
        $tag->assertFormFieldExists("name_translations.{$locale}");
        $ratingGroup->assertFormFieldExists("label_translations.{$locale}");
    }

    expect(app(LocaleManager::class)->isEnabled($withheld))->toBeFalse();
});

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
