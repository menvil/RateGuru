<?php

use App\Actions\Settings\SaveProjectSettingsAction;
use App\Filament\Pages\ProjectSettingsPage;
use App\Filament\Resources\Categories\Pages\CreateCategory;
use App\Filament\Resources\RatingGroups\Pages\CreateRatingGroup;
use App\Filament\Resources\Tags\Pages\CreateTag;
use App\Models\ProjectSettings;
use App\Models\User;
use App\Support\Locale\LocaleManager;
use App\Support\Settings\ProjectSettingsManager;
use Filament\Forms\Components\Select;
use Livewire\Livewire;

/**
 * Admin sees two different sets of languages on purpose. Translation editors
 * list every INSTALLED language, so content can be prepared before a language
 * is offered; the project default can only be a language the project OFFERS.
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

it('chooses the project default from a list of offered languages, not free text', function () {
    [$offered, $withheld] = withheldProject();

    Livewire::test(ProjectSettingsPage::class)
        ->assertFormFieldExists('default_locale', function (Select $field) use ($withheld): bool {
            $options = $field->getOptions();

            return array_keys($options) === app(LocaleManager::class)->enabledCodes()
                && ! array_key_exists($withheld, $options);
        })
        ->assertFormSet(['default_locale' => $offered]);
});

it('labels each offered language with its flag and native name', function () {
    Livewire::test(ProjectSettingsPage::class)
        ->assertFormFieldExists('default_locale', function (Select $field): bool {
            foreach (config('locales.supported') as $code => $info) {
                if (($field->getOptions()[$code] ?? null) !== "{$info['flag']} {$info['native']}") {
                    return false;
                }
            }

            return true;
        });
});

it('refuses a default the project does not offer', function (string $default) {
    [$offered] = withheldProject();

    Livewire::test(ProjectSettingsPage::class)
        ->set('data.default_locale', $default)
        ->call('save')
        ->assertHasErrors(['data.default_locale']);

    expect(ProjectSettings::findOrFail(1)->default_locale)->toBe($offered);
})->with([
    'installed but not offered' => fn () => twoTranslatedLocales()[1],
    'not installed' => fn () => unsupportedLocale(),
]);

it('saves an offered default', function () {
    [$offered] = withheldProject();

    Livewire::test(ProjectSettingsPage::class)
        ->set('data.default_locale', 'en')
        ->call('save')
        ->assertHasNoErrors();

    expect(ProjectSettings::findOrFail(1)->default_locale)->toBe('en')
        ->and(app(LocaleManager::class)->projectDefault())->toBe('en');
});

it('shows the default visitors actually get when the stored one is not offered', function (string $stored) {
    [$offered] = twoTranslatedLocales();
    ProjectSettings::query()->update(['enabled_locales' => json_encode(['en', $offered]), 'default_locale' => $stored]);
    app(ProjectSettingsManager::class)->flush();

    Livewire::test(ProjectSettingsPage::class)
        ->assertFormSet(['default_locale' => app(LocaleManager::class)->projectDefault()]);

    expect(app(LocaleManager::class)->projectDefault())->not->toBe($stored);
})->with([
    'withheld' => fn () => twoTranslatedLocales()[1],
    'not installed' => fn () => unsupportedLocale(),
]);

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
