<?php

use App\Enums\SocialProvider;
use App\Models\User;
use GuzzleHttp\Client;
use Laravel\Socialite\Facades\Socialite;

/*
 * The redirect half of the OAuth round trip, against the real Socialite
 * drivers (no network is involved: a redirect is only a URL). This is where
 * the "minimum data" and "never stateless" contracts are pinned.
 */

beforeEach(function () {
    config()->set('services.google.client_id', 'google-client-id');
    config()->set('services.google.client_secret', 'google-client-secret');
    config()->set('services.facebook.client_id', 'facebook-client-id');
    config()->set('services.facebook.client_secret', 'facebook-client-secret');
});

/** @return array<string, string> */
function redirectQuery(string $location): array
{
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    return $query;
}

it('redirects to Google asking only for identity, profile and email', function () {
    $response = $this->get('/auth/google');

    $response->assertRedirect();
    $location = (string) $response->headers->get('Location');
    $query = redirectQuery($location);

    expect($location)->toStartWith('https://accounts.google.com/o/oauth2/auth?')
        ->and($query['client_id'])->toBe('google-client-id')
        ->and($query['redirect_uri'])->toBe(url('/auth/google/callback'))
        ->and($query['scope'])->toBe('openid profile email')
        ->and($query['response_type'])->toBe('code')
        ->and($query['state'])->not->toBeEmpty();
});

it('redirects to Facebook asking only for the email permission', function () {
    $response = $this->get('/auth/facebook');

    $response->assertRedirect();
    $location = (string) $response->headers->get('Location');
    $query = redirectQuery($location);

    expect($location)->toStartWith('https://www.facebook.com/')
        ->and($location)->toContain('/dialog/oauth?')
        ->and($query['client_id'])->toBe('facebook-client-id')
        ->and($query['redirect_uri'])->toBe(url('/auth/facebook/callback'))
        ->and($query['scope'])->toBe('email')
        ->and($query['response_type'])->toBe('code')
        ->and($query['state'])->not->toBeEmpty();
});

it('binds the OAuth state to the session instead of going stateless', function (string $provider) {
    $response = $this->get('/auth/'.$provider);

    $query = redirectQuery((string) $response->headers->get('Location'));

    expect(session('state'))->toBe($query['state'])
        ->and(strlen($query['state']))->toBeGreaterThanOrEqual(32);
})->with(['google', 'facebook']);

it('bounds the time spent talking to a provider', function (string $provider) {
    $driver = Socialite::driver($provider);
    $client = (new ReflectionMethod($driver, 'getHttpClient'))->invoke($driver);

    expect($client)->toBeInstanceOf(Client::class)
        ->and($client->getConfig('connect_timeout'))->toBe(5)
        ->and($client->getConfig('timeout'))->toBe(15);
})->with(['google', 'facebook']);

it('never opts out of state verification anywhere in the integration', function () {
    foreach ([
        app_path('Support/Auth/SocialProviderGateway.php'),
        app_path('Http/Controllers/Auth/SocialAuthController.php'),
    ] as $path) {
        expect(file_get_contents($path))->not->toContain('->stateless(');
    }
});

it('rejects every provider that is not Google or Facebook', function (string $provider) {
    $this->get('/auth/'.$provider)->assertNotFound();
    $this->get('/auth/'.$provider.'/callback?code=x&state=y')->assertNotFound();
})->with(['github', 'twitter', 'apple', 'microsoft', 'GOOGLE', 'Facebook']);

it('rate limits the callback endpoint', function () {
    foreach (range(1, 20) as $attempt) {
        $this->get('/auth/google/callback?error=access_denied')->assertRedirect(route('login'));
    }

    $this->get('/auth/google/callback?error=access_denied')->assertTooManyRequests();
});

it('supports exactly Google and Facebook', function () {
    expect(SocialProvider::values())->toBe(['google', 'facebook']);
});

it('lets a signed-in person start the round trip to connect a provider', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/auth/google');

    $response->assertRedirect();
    expect((string) $response->headers->get('Location'))->toStartWith('https://accounts.google.com/');
});

it('shows the same Continue-with buttons on the login and registration pages', function (string $path) {
    $response = $this->get($path);

    $response->assertOk()
        ->assertSee('Continue with Google')
        ->assertSee('Continue with Facebook')
        ->assertDontSee('Register with Google')
        ->assertDontSee('Login with Google')
        ->assertSee(route('auth.social.redirect', ['provider' => 'google']))
        ->assertSee(route('auth.social.redirect', ['provider' => 'facebook']));
})->with(['/login', '/register']);
