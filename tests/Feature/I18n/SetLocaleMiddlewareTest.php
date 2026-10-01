<?php

use App\Filament\Resources\Tags\TagResource;
use App\Models\ProjectSettings;
use App\Models\User;
use App\Support\Settings\ProjectSettingsManager;

/**
 * Which language a public request is served in: the account, then the session,
 * then the cookie, then the browser, then the project default — and only ever
 * a language the project offers.
 *
 * Roles, not languages: the two languages besides English come from config,
 * so every case reads the same with any set of installed languages. With
 * today's catalogs they are Russian and Bulgarian.
 */
function servedLocale(): string
{
    return app()->getLocale();
}

// Account --------------------------------------------------------------------

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

it('skips an account language the project no longer offers, and keeps it on the account', function () {
    [$default, $withheld] = twoTranslatedLocales();
    offerLocales(array_values(array_diff(supportedLocales(), [$withheld])), $default);
    $user = User::factory()->create(['locale' => $withheld]);

    $this->actingAs($user)->withHeaders(noBrowserLanguage())->get(route('feed'))->assertOk()->assertSee('lang="'.$default.'"', false);

    expect(servedLocale())->toBe($default)
        ->and($user->fresh()->locale)->toBe($withheld)
        ->and($user->fresh()->preferredLocale())->toBeNull();
});

// Session --------------------------------------------------------------------

it('prefers the session to the cookie and the browser', function () {
    [$session, $other] = twoTranslatedLocales();

    $this->withSession(['locale' => $session])
        ->withCookie('locale', $other)
        ->withHeaders(acceptLanguage($other))
        ->get(route('feed'))
        ->assertOk();

    expect(servedLocale())->toBe($session);
});

it('skips a session language the project does not offer', function () {
    [$cookie, $withheld] = twoTranslatedLocales();
    offerLocales(array_values(array_diff(supportedLocales(), [$withheld])), 'en');

    $this->withSession(['locale' => $withheld])
        ->withCookie('locale', $cookie)
        ->get(route('feed'))
        ->assertOk();

    expect(servedLocale())->toBe($cookie)
        ->and(session('locale'))->toBe($withheld);
});

it('skips a session value that is not a language at all', function () {
    [$default] = twoTranslatedLocales();
    offerLocales(supportedLocales(), $default);

    $this->withSession(['locale' => unsupportedLocale()])->withHeaders(noBrowserLanguage())->get(route('feed'))->assertOk();

    expect(servedLocale())->toBe($default);
});

// Cookie ---------------------------------------------------------------------

it('prefers the cookie to the browser', function () {
    [$cookie, $browser] = twoTranslatedLocales();

    $this->withCookie('locale', $cookie)
        ->withHeaders(acceptLanguage($browser))
        ->get(route('feed'))
        ->assertOk();

    expect(servedLocale())->toBe($cookie);
});

it('skips a cookie language the project does not offer', function () {
    [$browser, $withheld] = twoTranslatedLocales();
    offerLocales(array_values(array_diff(supportedLocales(), [$withheld])), 'en');

    $this->withCookie('locale', $withheld)
        ->withHeaders(acceptLanguage($browser))
        ->get(route('feed'))
        ->assertOk();

    expect(servedLocale())->toBe($browser);
});

// Browser --------------------------------------------------------------------

it('serves the language of a regional browser setting', function (string $locale) {
    $this->withHeaders(acceptLanguage("{$locale}-".strtoupper($locale).",{$locale};q=0.9"))
        ->get(route('feed'))
        ->assertOk()
        ->assertSee('lang="'.$locale.'"', false);
})->with(translatedLocales());

it('follows the browser quality order', function () {
    [$preferred] = twoTranslatedLocales();

    $this->withHeaders(acceptLanguage("en;q=0.4,{$preferred};q=0.8"))->get(route('feed'))->assertOk();

    expect(servedLocale())->toBe($preferred);
});

it('skips a browser language the project does not offer', function () {
    [$offered, $withheld] = twoTranslatedLocales();
    offerLocales(array_values(array_diff(supportedLocales(), [$withheld])), 'en');

    $this->withHeaders(acceptLanguage("{$withheld}-".strtoupper($withheld).",{$withheld};q=0.9,{$offered};q=0.8"))
        ->get(route('feed'))
        ->assertOk();

    expect(servedLocale())->toBe($offered);
});

it('serves the project default when the browser asks for nothing on offer', function () {
    [$default] = twoTranslatedLocales();
    offerLocales(supportedLocales(), $default);

    $this->withHeaders(acceptLanguage(unsupportedLocale().'-XX,'.unsupportedLocale().';q=0.9'))
        ->get(route('feed'))
        ->assertOk();

    expect(servedLocale())->toBe($default);
});

it('never remembers a language it guessed from the browser', function () {
    [$browser] = twoTranslatedLocales();
    $user = User::factory()->create(['locale' => null]);

    $response = $this->actingAs($user)->withHeaders(acceptLanguage($browser))->get(route('feed'))->assertOk();

    expect(servedLocale())->toBe($browser)
        ->and(session()->has('locale'))->toBeFalse()
        ->and($response->headers->getCookies())->each(fn ($cookie) => $cookie->getName()->not->toBe('locale'))
        ->and($user->fresh()->locale)->toBeNull();
});

// Project default ------------------------------------------------------------

it('serves the project default to a visitor who has no preference at all', function () {
    [$default] = twoTranslatedLocales();
    offerLocales(supportedLocales(), $default);

    $this->withHeaders(noBrowserLanguage())->get(route('feed'))->assertOk()->assertSee('lang="'.$default.'"', false);

    expect(servedLocale())->toBe($default);
});

it('falls back to the technical locale only when the settings offer nothing usable', function () {
    ProjectSettings::factory()->create(['enabled_locales' => [unsupportedLocale()], 'default_locale' => unsupportedLocale()]);
    app(ProjectSettingsManager::class)->flush();

    $this->withHeaders(noBrowserLanguage())->get(route('feed'))->assertOk();

    expect(servedLocale())->toBe(config('locales.fallback'));
});

it('leaves the admin panel in English whatever the project default is', function () {
    [$default] = twoTranslatedLocales();
    offerLocales(supportedLocales(), $default);

    $this->actingAs(User::factory()->admin()->create(['locale' => $default]))
        ->withHeaders(acceptLanguage($default))
        ->get(TagResource::getUrl('create'))
        ->assertOk()
        ->assertSee('lang="en"', false);
});

// The three cases this change was specified by ---------------------------------

it('serves the project default to a browser that asks only for a withheld language', function () {
    // Russian installed but not offered, Bulgarian the default, a Russian browser.
    [$withheld, $default] = twoTranslatedLocales();
    offerLocales(array_values(array_diff(supportedLocales(), [$withheld])), $default);

    $this->withHeaders(acceptLanguage("{$withheld}-".strtoupper($withheld).",{$withheld};q=0.9"))->get(route('feed'))->assertOk();

    expect(servedLocale())->toBe($default);
});

it('serves the browser language over the project default when it is offered', function () {
    // Everything offered, Bulgarian the default, a browser preferring Russian.
    [$browser, $default] = twoTranslatedLocales();
    offerLocales(supportedLocales(), $default);

    $this->withHeaders(acceptLanguage("{$browser}-".strtoupper($browser).",{$browser};q=0.9,en;q=0.8"))->get(route('feed'))->assertOk();

    expect(servedLocale())->toBe($browser);
});

it('passes over a withheld account language to the cookie, before the browser', function () {
    // An account saved in Russian, Russian withdrawn, an English cookie and a
    // Bulgarian browser: the cookie is the visitor's own explicit choice.
    [$withheld, $browser] = twoTranslatedLocales();
    offerLocales(array_values(array_diff(supportedLocales(), [$withheld])), $browser);

    $this->actingAs(User::factory()->create(['locale' => $withheld]))
        ->withCookie('locale', 'en')
        ->withHeaders(acceptLanguage($browser))
        ->get(route('feed'))
        ->assertOk();

    expect(servedLocale())->toBe('en');
});
