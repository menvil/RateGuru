<?php

use App\Actions\Locale\ChangeLocaleAction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Cookie;

/** The `locale` cookie a response sets, if any. */
function localeCookie(TestResponse $response): ?Cookie
{
    return collect($response->headers->getCookies())->first(fn (Cookie $cookie): bool => $cookie->getName() === 'locale');
}

it('remembers a guest choice in the session and in a cookie', function (string $locale) {
    offerEveryInstalledLocale();

    $this->post(route('locale.change'), ['locale' => $locale])
        ->assertRedirect()
        ->assertCookie('locale', $locale);

    expect(session('locale'))->toBe($locale);
})->with(supportedLocales());

it('remembers a signed-in choice on the account, in the session and in a cookie', function (string $locale) {
    offerEveryInstalledLocale();
    $user = User::factory()->create(['locale' => null]);

    $this->actingAs($user)
        ->post(route('locale.change'), ['locale' => $locale])
        ->assertRedirect()
        ->assertCookie('locale', $locale);

    expect($user->fresh()->locale)->toBe($locale)
        ->and(session('locale'))->toBe($locale);
})->with(supportedLocales());

it('sets a long-lived, site-wide cookie that scripts cannot read', function () {
    $cookie = localeCookie($this->post(route('locale.change'), ['locale' => 'en']));

    expect($cookie)->not->toBeNull()
        ->and($cookie->getPath())->toBe('/')
        ->and($cookie->isHttpOnly())->toBeTrue()
        ->and($cookie->getSameSite())->toBe(Cookie::SAMESITE_LAX)
        ->and($cookie->getExpiresTime())->toBeGreaterThan(now()->addDays(364)->getTimestamp());
});

it('serves the next visit in the remembered language', function () {
    [$locale] = twoTranslatedLocales();
    $cookie = localeCookie($this->post(route('locale.change'), ['locale' => $locale]));

    $this->flushSession();
    $this->withHeaders(noBrowserLanguage())
        ->withUnencryptedCookie('locale', $cookie->getValue())
        ->get(route('feed'))
        ->assertOk()
        ->assertSee('lang="'.$locale.'"', false);
});

it('refuses a language the project does not offer, and changes nothing', function () {
    [$offered, $withheld] = twoTranslatedLocales();
    offerLocales(array_values(array_diff(supportedLocales(), [$withheld])));
    $user = User::factory()->create(['locale' => $offered]);

    $response = $this->actingAs($user)
        ->withSession(['locale' => $offered])
        ->post(route('locale.change'), ['locale' => $withheld])
        ->assertSessionHasErrors('locale');

    expect($user->fresh()->locale)->toBe($offered)
        ->and(session('locale'))->toBe($offered)
        ->and(localeCookie($response))->toBeNull();
});

it('refuses a language that is not installed', function () {
    $response = $this->post(route('locale.change'), ['locale' => unsupportedLocale()])
        ->assertSessionHasErrors('locale');

    expect(session()->has('locale'))->toBeFalse()
        ->and(localeCookie($response))->toBeNull();
});

it('refuses, rather than corrects, a locale nobody offers when called directly', function () {
    // Validation keeps these out of the HTTP route; the action itself must
    // not turn `xx` into the fallback and save that instead.
    $user = User::factory()->create(['locale' => 'en']);
    $request = Request::create('/locale', 'POST');
    $request->setLaravelSession(app('session.store'));
    $request->setUserResolver(fn () => $user);

    expect(fn () => app(ChangeLocaleAction::class)->execute(unsupportedLocale(), $request))
        ->toThrow(InvalidArgumentException::class);

    expect($user->fresh()->locale)->toBe('en')
        ->and($request->session()->has('locale'))->toBeFalse();
});
