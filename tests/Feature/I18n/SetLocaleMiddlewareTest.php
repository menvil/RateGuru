<?php

use App\Filament\Pages\LanguagesPage;
use App\Filament\Resources\Tags\TagResource;
use App\Models\ProjectSettings;
use App\Models\User;
use App\Support\Settings\ProjectSettingsManager;
use Livewire\Livewire;

/**
 * Which language a public request is served in: the account, then the session,
 * then the cookie, then the browser — the first of them the project offers —
 * and English, the default, only when none of them is.
 *
 * A stored choice the project no longer offers is skipped, not deleted: the
 * search goes on to the next place, and the choice applies again once the
 * language is offered again.
 *
 * Roles, not languages: the two languages besides English come from config,
 * so every case reads the same with any set of installed languages.
 */
function servedLocale(): string
{
    return app()->getLocale();
}

// A choice that is offered -------------------------------------------------------

it('serves the account language before anything else', function () {
    [$account, $other] = twoTranslatedLocales();

    $this->actingAs(User::factory()->create(['locale' => $account]))
        ->withSession(['locale' => 'en'])
        ->withCookie('locale', $other)
        ->withHeaders(acceptLanguage($other))
        ->get(route('feed'))
        ->assertOk();

    expect(servedLocale())->toBe($account);
});

it('prefers the session to the cookie and the browser', function () {
    [$session, $other] = twoTranslatedLocales();

    $this->withSession(['locale' => $session])
        ->withCookie('locale', $other)
        ->withHeaders(acceptLanguage($other))
        ->get(route('feed'))
        ->assertOk();

    expect(servedLocale())->toBe($session);
});

it('prefers the cookie to the browser', function () {
    [$cookie, $browser] = twoTranslatedLocales();

    $this->withCookie('locale', $cookie)
        ->withHeaders(acceptLanguage($browser))
        ->get(route('feed'))
        ->assertOk();

    expect(servedLocale())->toBe($cookie);
});

// A choice that is no longer offered is skipped ----------------------------------------

it('skips an account language the project no longer offers, to the browser, and keeps it on the account', function () {
    [$browser, $chosen] = twoTranslatedLocales();
    offerEveryInstalledLocaleExcept($chosen);
    $user = User::factory()->create(['locale' => $chosen]);

    $this->actingAs($user)->withHeaders(acceptLanguage($browser))->get(route('feed'))->assertOk()->assertSee('lang="'.$browser.'"', false);

    expect(servedLocale())->toBe($browser)
        ->and($user->fresh()->locale)->toBe($chosen);
});

it('skips a session language the project no longer offers, to the browser, and keeps it in the session', function () {
    [$browser, $chosen] = twoTranslatedLocales();
    offerEveryInstalledLocaleExcept($chosen);

    $this->withSession(['locale' => $chosen])
        ->withHeaders(acceptLanguage($browser))
        ->get(route('feed'))
        ->assertOk();

    expect(servedLocale())->toBe($browser)
        ->and(session('locale'))->toBe($chosen);
});

it('skips a cookie language the project no longer offers, to the browser, and leaves the cookie alone', function () {
    [$browser, $chosen] = twoTranslatedLocales();
    offerEveryInstalledLocaleExcept($chosen);

    $response = $this->withCookie('locale', $chosen)
        ->withHeaders(acceptLanguage($browser))
        ->get(route('feed'))
        ->assertOk();

    expect(servedLocale())->toBe($browser)
        // Kept as the visitor left it: nothing rewrites or clears the cookie.
        ->and($response->headers->getCookies())->each(fn ($cookie) => $cookie->getName()->not->toBe('locale'));
});

it('passes over a withheld account language to the cookie, before the browser', function () {
    // An account saved in one language, that language withdrawn, an English
    // cookie and a browser asking for another: the cookie is the visitor's own
    // choice, and comes before the browser.
    [$browser, $chosen] = twoTranslatedLocales();
    offerEveryInstalledLocaleExcept($chosen);

    $this->actingAs(User::factory()->create(['locale' => $chosen]))
        ->withCookie('locale', 'en')
        ->withHeaders(acceptLanguage($browser))
        ->get(route('feed'))
        ->assertOk();

    expect(servedLocale())->toBe('en');
});

it('skips a session value that is not a language at all', function () {
    [$browser] = twoTranslatedLocales();

    $this->withSession(['locale' => unsupportedLocale()])->withHeaders(acceptLanguage($browser))->get(route('feed'))->assertOk();

    expect(servedLocale())->toBe($browser);
});

it('serves English to a stored choice no longer offered when the browser asks for nothing on offer', function (string $header) {
    [$other, $chosen] = twoTranslatedLocales();
    offerEveryInstalledLocaleExcept($chosen, $other);
    $user = User::factory()->create(['locale' => $chosen]);

    $this->actingAs($user)
        ->withHeaders(acceptLanguage(str_replace('{other}', $other, $header)))
        ->get(route('feed'))
        ->assertOk()
        ->assertSee('lang="en"', false);

    expect(servedLocale())->toBe('en')
        ->and($user->fresh()->locale)->toBe($chosen);
})->with([
    'a disabled language' => '{other}-XX,{other};q=0.9',
    'an unknown language' => fn () => unsupportedLocale().'-XX,'.unsupportedLocale().';q=0.9',
    'English itself' => 'en-GB,en;q=0.9',
    'nothing at all' => '',
]);

it('serves the stored choice again once its language is offered again', function () {
    [$browser, $chosen] = twoTranslatedLocales();
    $user = User::factory()->create(['locale' => $chosen]);

    offerEveryInstalledLocaleExcept($chosen);
    $this->actingAs($user)->withHeaders(acceptLanguage($browser))->get(route('feed'))->assertOk();
    expect(servedLocale())->toBe($browser);

    offerEveryInstalledLocale();
    $this->actingAs($user)->withHeaders(acceptLanguage($browser))->get(route('feed'))->assertOk();
    expect(servedLocale())->toBe($chosen);
});

it('takes a visitor whose chosen language is disabled to their browser language, and back once it is enabled', function () {
    // End to end: the visitor picks a language with the switcher, an
    // administrator disables it on the Languages page, the visitor's browser
    // prefers a language that is still offered.
    [$browser, $chosen] = twoTranslatedLocales();
    ProjectSettings::factory()->create();
    offerEveryInstalledLocale();
    $visitor = User::factory()->create(['locale' => null]);

    $this->actingAs($visitor)->post(route('locale.change'), ['locale' => $chosen])->assertRedirect();
    expect($visitor->fresh()->locale)->toBe($chosen);

    $this->actingAs(User::factory()->admin()->create());
    Livewire::test(LanguagesPage::class)->call('disableLanguage', $chosen);
    app(ProjectSettingsManager::class)->flush();

    $this->actingAs($visitor)->withHeaders(acceptLanguage($browser))->get(route('feed'))->assertOk()->assertSee('lang="'.$browser.'"', false);
    expect(servedLocale())->toBe($browser)
        ->and($visitor->fresh()->locale)->toBe($chosen);

    $this->actingAs(User::factory()->admin()->create());
    Livewire::test(LanguagesPage::class)->call('enableLanguage', $chosen);
    app(ProjectSettingsManager::class)->flush();

    $this->actingAs($visitor)->withHeaders(acceptLanguage($browser))->get(route('feed'))->assertOk()->assertSee('lang="'.$chosen.'"', false);
    expect(servedLocale())->toBe($chosen);
});

// No choice: the browser ---------------------------------------------------------------

it('serves an offered browser language to a visitor who chose nothing', function () {
    [$browser] = twoTranslatedLocales();
    offerEveryInstalledLocale();

    $this->withHeaders(acceptLanguage("{$browser}-".strtoupper($browser).",{$browser};q=0.9,en;q=0.8"))->get(route('feed'))->assertOk();

    expect(servedLocale())->toBe($browser);
});

it('serves the language of a regional browser setting', function (string $locale) {
    offerEveryInstalledLocale();

    $this->withHeaders(acceptLanguage("{$locale}-".strtoupper($locale).",{$locale};q=0.9"))
        ->get(route('feed'))
        ->assertOk()
        ->assertSee('lang="'.$locale.'"', false);
})->with(representativeTranslatedLocales());

it('follows the browser quality order', function () {
    [$preferred] = twoTranslatedLocales();

    $this->withHeaders(acceptLanguage("en;q=0.4,{$preferred};q=0.8"))->get(route('feed'))->assertOk();

    expect(servedLocale())->toBe($preferred);
});

it('skips a browser language the project does not offer', function () {
    [$offered, $withheld] = twoTranslatedLocales();
    offerEveryInstalledLocaleExcept($withheld);

    $this->withHeaders(acceptLanguage("{$withheld}-".strtoupper($withheld).",{$withheld};q=0.9,{$offered};q=0.8"))
        ->get(route('feed'))
        ->assertOk();

    expect(servedLocale())->toBe($offered);
});

it('serves English to a browser that asks only for languages not on offer', function (string $header) {
    [, $withheld] = twoTranslatedLocales();
    offerEveryInstalledLocaleExcept($withheld);

    $this->withHeaders(acceptLanguage(str_replace('{withheld}', $withheld, $header)))->get(route('feed'))->assertOk();

    expect(servedLocale())->toBe('en');
})->with([
    'a disabled language' => '{withheld}-XX,{withheld};q=0.9',
    'an unknown language' => fn () => unsupportedLocale().'-XX,'.unsupportedLocale().';q=0.9',
]);

it('never remembers a language it guessed from the browser', function () {
    [$browser] = twoTranslatedLocales();
    $user = User::factory()->create(['locale' => null]);

    $response = $this->actingAs($user)->withHeaders(acceptLanguage($browser))->get(route('feed'))->assertOk();

    expect(servedLocale())->toBe($browser)
        ->and(session()->has('locale'))->toBeFalse()
        ->and($response->headers->getCookies())->each(fn ($cookie) => $cookie->getName()->not->toBe('locale'))
        ->and($user->fresh()->locale)->toBeNull();
});

// Nothing at all ---------------------------------------------------------------------

it('serves English to a visitor who has no preference at all', function () {
    offerEveryInstalledLocale();

    $this->withHeaders(noBrowserLanguage())->get(route('feed'))->assertOk()->assertSee('lang="en"', false);

    expect(servedLocale())->toBe('en');
});

it('serves English when the settings row offers nothing usable', function () {
    ProjectSettings::factory()->create(['enabled_locales' => [unsupportedLocale()]]);
    app(ProjectSettingsManager::class)->flush();

    $this->withHeaders(noBrowserLanguage())->get(route('feed'))->assertOk();

    expect(servedLocale())->toBe('en');
});

it('leaves the admin panel in English whatever the visitor chose', function () {
    [$chosen] = twoTranslatedLocales();
    offerLocales(supportedLocales());

    $this->actingAs(User::factory()->admin()->create(['locale' => $chosen]))
        ->withHeaders(acceptLanguage($chosen))
        ->get(TagResource::getUrl('create'))
        ->assertOk()
        ->assertSee('lang="en"', false);
});
