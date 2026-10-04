<?php

use App\Livewire\Settings\UserLocaleSettings;
use App\Models\User;
use Livewire\Livewire;

it('allows authenticated user to update locale preference', function (string $locale) {
    offerEveryInstalledLocale();
    $user = User::factory()->create(['locale' => 'en']);

    Livewire::actingAs($user)
        ->test(UserLocaleSettings::class)
        ->set('locale', $locale)
        ->call('save')
        ->assertHasNoErrors();

    expect($user->fresh()->locale)->toBe($locale);
})->with(translatedLocales());

it('reloads the page after saving, so all of it shows the new language at once', function () {
    [$locale] = twoTranslatedLocales();
    offerEveryInstalledLocale();
    $user = User::factory()->create(['locale' => 'en']);

    Livewire::actingAs($user)
        ->test(UserLocaleSettings::class)
        ->set('locale', $locale)
        ->call('save')
        ->assertRedirect(route('profile.edit'));

    $this->actingAs($user->fresh())->get(route('profile.edit'))->assertOk()->assertSee('lang="'.$locale.'"', false);
});

it('rejects unsupported user locale preference', function () {
    $user = User::factory()->create(['locale' => 'en']);

    Livewire::actingAs($user)
        ->test(UserLocaleSettings::class)
        ->set('locale', unsupportedLocale())
        ->call('save')
        ->assertHasErrors('locale')
        ->assertNoRedirect();
});

it('renders user locale settings on profile page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertSee(__('ui.settings.language'));
});

it('offers only the languages the project offers, with their flags', function () {
    [$offered, $withheld] = twoTranslatedLocales();
    offerEveryInstalledLocaleExcept($withheld);

    $component = Livewire::actingAs(User::factory()->create(['locale' => $offered]))
        ->test(UserLocaleSettings::class);

    foreach (array_diff(supportedLocales(), [$withheld]) as $code) {
        $component->assertSeeHtml('<option value="'.$code.'">'.config("locales.supported.{$code}.flag").' '.config("locales.supported.{$code}.native").'</option>');
    }

    $component->assertDontSeeHtml('<option value="'.$withheld.'">');
});

it('refuses a language that is installed but not offered', function () {
    [$offered, $withheld] = twoTranslatedLocales();
    offerEveryInstalledLocaleExcept($withheld);
    $user = User::factory()->create(['locale' => $offered]);

    Livewire::actingAs($user)
        ->test(UserLocaleSettings::class)
        ->set('locale', $withheld)
        ->call('save')
        ->assertHasErrors('locale');

    expect($user->fresh()->locale)->toBe($offered);
});

it('preselects a language that can be saved when the stored one is no longer offered', function () {
    [$offered, $withheld] = twoTranslatedLocales();
    offerEveryInstalledLocaleExcept($withheld);
    $user = User::factory()->create(['locale' => $withheld]);

    $this->actingAs($user)->withHeaders(noBrowserLanguage())->get(route('profile.edit'))->assertOk();

    Livewire::actingAs($user)
        ->test(UserLocaleSettings::class)
        ->assertSet('locale', app()->getLocale());

    expect(app()->getLocale())->not->toBe($withheld)
        ->and($user->fresh()->locale)->toBe($withheld);
});
