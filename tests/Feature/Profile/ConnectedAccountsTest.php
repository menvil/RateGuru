<?php

use App\Models\SocialAccount;
use App\Models\User;
use App\Notifications\SocialAccountDisconnectedNotification;
use App\Support\Auth\SocialLinkContext;
use Illuminate\Support\Facades\Notification;
use Laravel\Socialite\Facades\Socialite;

/*
 * The profile's Connected accounts card: which Google and Facebook accounts
 * sign in to this account, connecting another one, and disconnecting one —
 * never the last way in.
 */

beforeEach(function () {
    config()->set('services.google.client_id', 'google-client-id');
    config()->set('services.google.client_secret', 'google-client-secret');
    config()->set('services.facebook.client_id', 'facebook-client-id');
    config()->set('services.facebook.client_secret', 'facebook-client-secret');
});

it('lists both providers with the connected account and its email', function () {
    $user = User::factory()->create();
    SocialAccount::factory()->for($user)->google()->create(['provider_email' => 'ivan.personal@gmail.com']);

    $this->actingAs($user)->get('/profile')
        ->assertOk()
        ->assertSee('id="connected-accounts"', false)
        ->assertSee(__('profile.connected.title'))
        ->assertSee('data-testid="connected-account-google"', false)
        ->assertSee('data-testid="connected-account-facebook"', false)
        ->assertSee('ivan.personal@gmail.com')
        ->assertSee('data-testid="disconnect-google"', false)
        ->assertSee('data-testid="connect-facebook"', false)
        ->assertSee(route('profile.connected-accounts.store', ['provider' => 'facebook']), false)
        ->assertDontSee('data-testid="connect-google"', false)
        ->assertDontSee('data-testid="disconnect-facebook"', false);
});

it('says so when the connected account shared no email', function () {
    $user = User::factory()->create();
    SocialAccount::factory()->for($user)->facebook()->create(['provider_email' => null]);

    $this->actingAs($user)->get('/profile')
        ->assertOk()
        ->assertSee(__('profile.connected.no_email'));
});

it('offers to connect both providers to an account that has none', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/profile')
        ->assertOk()
        ->assertSee('data-testid="connect-google"', false)
        ->assertSee('data-testid="connect-facebook"', false)
        ->assertDontSee('data-testid="disconnect-', false);
});

it('never offers to disconnect the only way into an account without a password', function () {
    $user = User::factory()->withoutPassword()->create();
    SocialAccount::factory()->for($user)->google()->create();

    $this->actingAs($user)->get('/profile')
        ->assertOk()
        ->assertDontSee('data-testid="disconnect-google"', false)
        ->assertSee('data-testid="connected-account-last-method"', false)
        ->assertSee(__('profile.connected.last_method'));
});

it('offers to disconnect either provider of an account without a password that has both', function () {
    $user = User::factory()->withoutPassword()->create();
    SocialAccount::factory()->for($user)->google()->create();
    SocialAccount::factory()->for($user)->facebook()->create();

    $this->actingAs($user)->get('/profile')
        ->assertOk()
        ->assertSee('data-testid="disconnect-google"', false)
        ->assertSee('data-testid="disconnect-facebook"', false)
        ->assertDontSee('data-testid="connected-account-last-method"', false);
});

it('starts the provider round trip from the profile', function (string $provider, string $host) {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('profile.connected-accounts.store', ['provider' => $provider]));

    $response->assertRedirect();
    expect((string) $response->headers->get('Location'))->toStartWith($host);
})->with([
    'google' => ['google', 'https://accounts.google.com/'],
    'facebook' => ['facebook', 'https://www.facebook.com/'],
]);

it('connects through the round trip and comes back to the card', function () {
    $user = User::factory()->create(['email' => 'ivan@example.com']);

    startConnectingProvider($user, 'google');
    Socialite::fake('google', fakeSocialiteUser(['id' => 'g-1', 'email' => 'ivan.personal@gmail.com']));
    $response = $this->get(socialCallbackUrl('google'));

    $response->assertRedirect(connectedAccountsUrl())
        ->assertSessionHas('connected_accounts_status', __('profile.connected.connected', ['provider' => 'Google']));

    $this->followRedirects($response)
        ->assertOk()
        ->assertSee(__('profile.connected.connected', ['provider' => 'Google']))
        ->assertSee('ivan.personal@gmail.com');
});

it('shows a refused connection on the card', function () {
    $user = User::factory()->create(['email' => 'ivan@example.com']);
    User::factory()->create(['email' => 'maria@example.com']);
    Socialite::fake('facebook', fakeSocialiteUser(['id' => 'fb-1', 'email' => 'maria@example.com']));

    $response = startConnectingProvider($user, 'facebook')->get(socialCallbackUrl('facebook'));

    $this->followRedirects($response)
        ->assertOk()
        ->assertSee('data-testid="connected-accounts-error"', false)
        ->assertSee(e(__('auth.social.email_taken', ['provider' => 'Facebook'])), false);
});

it('shows a cancelled connection on the card', function () {
    $user = User::factory()->create();

    startConnectingProvider($user, 'google')
        ->get(socialCallbackUrl('google', ['error' => 'access_denied', 'code' => null]))
        ->assertRedirect(connectedAccountsUrl())
        ->assertSessionHasErrors(['social' => __('auth.social.cancelled', ['provider' => 'Google'])], null, 'connectedAccounts');

    expect(SocialAccount::count())->toBe(0);
});

it('brings a connection back to the card even when an abandoned modal attempt is remembered', function () {
    $user = User::factory()->create(['email' => 'ivan@example.com']);
    Socialite::fake('google', fakeSocialiteUser(['id' => 'g-1', 'email' => 'ivan@example.com']));

    startConnectingProvider($user, 'google')
        ->withSession(['auth.surface_context' => [
            '_auth_surface' => 'modal',
            '_auth_mode' => 'login',
            '_auth_return_to' => '/about',
        ]])
        ->get(socialCallbackUrl('google'))
        ->assertRedirect(connectedAccountsUrl());

    expect(session()->has('auth.surface_context'))->toBeFalse()
        ->and($user->socialAccounts()->count())->toBe(1);
});

/*
 * A callback connects only what the card asked for. OAuth state proves the
 * callback belongs to this session's round trip; the connection context
 * recorded by Connect proves which account asked for which provider. Being
 * signed in when the callback arrives proves nothing.
 */

it('records which account asked for which provider before leaving for the provider', function () {
    $user = User::factory()->create();

    startConnectingProvider($user, 'facebook');

    expect(session(SocialLinkContext::SESSION_KEY))->toMatchArray([
        'user_id' => $user->id,
        'provider' => 'facebook',
    ])->and(array_keys(session(SocialLinkContext::SESSION_KEY)))->toEqualCanonicalizing(['user_id', 'provider', 'created_at']);
});

it('never turns a sign-in started as a guest into a connection once the session is signed in', function () {
    $user = User::factory()->create(['email' => 'ivan@example.com']);
    Socialite::fake('google', fakeSocialiteUser(['id' => 'g-1', 'email' => 'ivan.personal@gmail.com']));

    $this->get('/auth/google')->assertRedirect();
    $this->actingAs($user);

    $this->get(socialCallbackUrl('google'))
        ->assertRedirect(connectedAccountsUrl())
        ->assertSessionHasErrors(['social' => __('auth.social.already_signed_in', ['provider' => 'Google'])], null, 'connectedAccounts');

    $this->assertAuthenticatedAs($user);
    expect(SocialAccount::count())->toBe(0)->and(User::count())->toBe(1);
});

it('never switches a signed-in session to the account behind the identity', function () {
    $signedIn = User::factory()->create();
    $owner = User::factory()->create();
    SocialAccount::factory()->for($owner)->google()->create(['provider_user_id' => 'g-1']);
    Socialite::fake('google', fakeSocialiteUser(['id' => 'g-1', 'email' => $owner->email]));

    $this->actingAs($signedIn)->get(socialCallbackUrl('google'))
        ->assertSessionHasErrors(['social'], null, 'connectedAccounts');

    $this->assertAuthenticatedAs($signedIn);
    expect(SocialAccount::query()->sole()->user_id)->toBe($owner->id);
});

it('neither signs in, registers nor connects when the account signed out before the callback', function () {
    $user = User::factory()->create(['email' => 'ivan@example.com']);
    Socialite::fake('google', fakeSocialiteUser(['id' => 'g-1', 'email' => 'someone-new@gmail.com']));

    startConnectingProvider($user, 'google');
    // Signed out without the session being flushed — the connection
    // context is still there, and must not become a guest sign-in.
    auth()->guard('web')->logout();

    $this->get(socialCallbackUrl('google'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['social' => __('auth.social.link_expired')]);

    $this->assertGuest();
    expect(User::count())->toBe(1)
        ->and(SocialAccount::count())->toBe(0)
        ->and(session()->has(SocialLinkContext::SESSION_KEY))->toBeFalse();
});

it('refuses the callback of a connection whose account logged out, at the OAuth state', function () {
    $user = User::factory()->create(['email' => 'ivan@example.com']);

    // The real driver: logging out flushes the session, and with it both the
    // connection context and the OAuth state this callback would need.
    startConnectingProvider($user, 'google');
    $state = (string) session('state');
    $this->post('/logout');

    $this->get(socialCallbackUrl('google', ['state' => $state]))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['social' => __('auth.social.expired', ['provider' => 'Google'])]);

    $this->assertGuest();
    expect(User::count())->toBe(1)->and(SocialAccount::count())->toBe(0);
});

it('connects nothing when the callback arrives signed in to another account', function () {
    $starter = User::factory()->create(['email' => 'ivan@example.com']);
    $other = User::factory()->create(['email' => 'maria@example.com']);
    Socialite::fake('google', fakeSocialiteUser(['id' => 'g-1', 'email' => 'someone-new@gmail.com']));

    startConnectingProvider($starter, 'google');
    $this->actingAs($other);

    $this->get(socialCallbackUrl('google'))
        ->assertRedirect(connectedAccountsUrl())
        ->assertSessionHasErrors(['social' => __('auth.social.link_expired')], null, 'connectedAccounts');

    $this->assertAuthenticatedAs($other);
    expect(SocialAccount::count())->toBe(0);
});

it('never lets a connection started for one provider finish with another', function () {
    $user = User::factory()->create();
    Socialite::fake('facebook', fakeSocialiteUser(['id' => 'fb-1', 'email' => 'ivan.personal@example.com']));
    Socialite::fake('google', fakeSocialiteUser(['id' => 'g-1', 'email' => 'ivan.personal@gmail.com']));

    startConnectingProvider($user, 'google')
        ->get(socialCallbackUrl('facebook'))
        ->assertSessionHasErrors(['social' => __('auth.social.link_expired')], null, 'connectedAccounts');

    // Consumed: the provider it was started for cannot use it afterwards.
    $this->get(socialCallbackUrl('google'))
        ->assertSessionHasErrors(['social' => __('auth.social.already_signed_in', ['provider' => 'Google'])], null, 'connectedAccounts');

    expect(SocialAccount::count())->toBe(0);
});

it('lets a connection finish within ten minutes of pressing Connect', function () {
    $user = User::factory()->create();
    Socialite::fake('google', fakeSocialiteUser(['id' => 'g-1', 'email' => 'ivan.personal@gmail.com']));

    startConnectingProvider($user, 'google');
    $this->travel(9)->minutes();

    $this->get(socialCallbackUrl('google'))->assertSessionHasNoErrors();

    expect($user->socialAccounts()->count())->toBe(1);
});

it('connects nothing once the connection has expired', function () {
    $user = User::factory()->create();
    Socialite::fake('google', fakeSocialiteUser(['id' => 'g-1', 'email' => 'ivan.personal@gmail.com']));

    startConnectingProvider($user, 'google');
    $this->travel(SocialLinkContext::LIFETIME_MINUTES + 1)->minutes();

    $this->get(socialCallbackUrl('google'))
        ->assertRedirect(connectedAccountsUrl())
        ->assertSessionHasErrors(['social' => __('auth.social.link_expired')], null, 'connectedAccounts');

    expect(SocialAccount::count())->toBe(0);
});

it('uses a connection once: a replayed callback connects nothing', function () {
    $user = User::factory()->create();
    Socialite::fake('google', fakeSocialiteUser(['id' => 'g-1', 'email' => 'ivan.personal@gmail.com']));

    startConnectingProvider($user, 'google')->get(socialCallbackUrl('google'))->assertSessionHasNoErrors();
    expect(session()->has(SocialLinkContext::SESSION_KEY))->toBeFalse();

    $user->socialAccounts()->delete();

    $this->get(socialCallbackUrl('google'))
        ->assertSessionHasErrors(['social' => __('auth.social.already_signed_in', ['provider' => 'Google'])], null, 'connectedAccounts');

    expect(SocialAccount::count())->toBe(0);
});

it('never reads a connection context it did not write', function (mixed $payload) {
    $user = User::factory()->create();
    Socialite::fake('google', fakeSocialiteUser(['id' => 'g-1', 'email' => 'ivan.personal@gmail.com']));

    $this->actingAs($user)
        ->withSession([SocialLinkContext::SESSION_KEY => $payload])
        ->get(socialCallbackUrl('google'))
        ->assertSessionHasErrors(['social' => __('auth.social.link_expired')], null, 'connectedAccounts');

    expect(SocialAccount::count())->toBe(0);
})->with([
    'not an array' => ['google'],
    'a string user id' => [['user_id' => '1', 'provider' => 'google', 'created_at' => 1]],
    'an unknown provider' => [['user_id' => 1, 'provider' => 'github', 'created_at' => 1]],
    'no timestamp' => [['user_id' => 1, 'provider' => 'google']],
]);

it('discards the OAuth state of a callback it refuses, so it cannot be replayed into a sign-in', function () {
    $user = User::factory()->create();

    startConnectingProvider($user, 'google');
    expect(session()->has('state'))->toBeTrue();
    auth()->guard('web')->logout();

    $this->get(socialCallbackUrl('google'))->assertSessionHasErrors(['social' => __('auth.social.link_expired')]);

    expect(session()->has('state'))->toBeFalse();
});

it('lets only a signed-in person connect or disconnect', function (string $method) {
    $this->{$method}(route('profile.connected-accounts.'.($method === 'post' ? 'store' : 'destroy'), ['provider' => 'google']))
        ->assertRedirect(route('login'));
})->with(['post', 'delete']);

it('accepts no provider outside the closed list', function (string $method) {
    $user = User::factory()->create();

    $this->actingAs($user)->{$method}('/profile/connected-accounts/github')->assertNotFound();
})->with(['post', 'delete']);

it('disconnects a provider and emails the account about it', function () {
    Notification::fake();
    $user = User::factory()->create();
    SocialAccount::factory()->for($user)->google()->create(['provider_email' => 'ivan.personal@gmail.com']);
    $facebook = SocialAccount::factory()->for($user)->facebook()->create();

    $response = $this->actingAs($user)->delete(route('profile.connected-accounts.destroy', ['provider' => 'google']));

    $response->assertRedirect(connectedAccountsUrl())
        ->assertSessionHasNoErrors()
        ->assertSessionHas('connected_accounts_status', __('profile.connected.disconnected', ['provider' => 'Google']));
    expect(SocialAccount::query()->where('user_id', $user->id)->pluck('id')->all())->toBe([$facebook->id]);

    Notification::assertSentTo(
        $user,
        SocialAccountDisconnectedNotification::class,
        fn (SocialAccountDisconnectedNotification $notification): bool => $notification->provider->value === 'google'
            && $notification->providerEmail === 'ivan.personal@gmail.com',
    );
});

it('disconnects one provider of an account without a password that has both', function () {
    $user = User::factory()->withoutPassword()->create();
    SocialAccount::factory()->for($user)->google()->create();
    SocialAccount::factory()->for($user)->facebook()->create();

    $this->actingAs($user)->delete(route('profile.connected-accounts.destroy', ['provider' => 'facebook']))
        ->assertSessionHasNoErrors();

    expect($user->socialAccounts()->pluck('provider')->map->value->all())->toBe(['google']);
});

it('refuses to disconnect the only way into an account without a password', function () {
    Notification::fake();
    $user = User::factory()->withoutPassword()->create();
    SocialAccount::factory()->for($user)->google()->create();

    $response = $this->actingAs($user)->delete(route('profile.connected-accounts.destroy', ['provider' => 'google']));

    $response->assertRedirect(connectedAccountsUrl())
        ->assertSessionHasErrors(['social' => __('profile.connected.last_method_error', ['provider' => 'Google'])], null, 'connectedAccounts');
    expect($user->socialAccounts()->count())->toBe(1);
    Notification::assertNothingSent();
});

it('refuses to disconnect a provider that is not connected', function () {
    Notification::fake();
    $user = User::factory()->create();

    $this->actingAs($user)->delete(route('profile.connected-accounts.destroy', ['provider' => 'facebook']))
        ->assertRedirect(connectedAccountsUrl())
        ->assertSessionHasErrors(['social' => __('profile.connected.not_connected_error', ['provider' => 'Facebook'])], null, 'connectedAccounts');

    Notification::assertNothingSent();
});

it('never disconnects another account\'s provider', function () {
    $user = User::factory()->create();
    $other = SocialAccount::factory()->google()->create();

    $this->actingAs($user)->delete(route('profile.connected-accounts.destroy', ['provider' => 'google']))
        ->assertSessionHasErrors(['social'], null, 'connectedAccounts');

    expect($other->fresh())->not->toBeNull();
});

it('refreshes the shown email when the provider reports a new one at sign-in', function () {
    $user = User::factory()->create();
    SocialAccount::factory()->for($user)->google()->create([
        'provider_user_id' => 'g-1',
        'provider_email' => 'old@gmail.com',
    ]);
    Socialite::fake('google', fakeSocialiteUser(['id' => 'g-1', 'email' => 'New@Gmail.com']));

    $this->get(socialCallbackUrl('google'));

    $this->assertAuthenticatedAs($user);
    expect($user->socialAccounts()->sole()->provider_email)->toBe('new@gmail.com');
});

it('keeps the provider email of an account created through the provider', function () {
    Socialite::fake('facebook', fakeSocialiteUser(['id' => 'fb-1', 'email' => 'Ivan@Example.com']));

    $this->get(socialCallbackUrl('facebook'));

    expect(SocialAccount::query()->sole()->provider_email)->toBe('ivan@example.com');
});

it('limits how often a provider can be connected or disconnected', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    for ($attempt = 0; $attempt < 10; $attempt++) {
        $this->delete(route('profile.connected-accounts.destroy', ['provider' => 'google']));
    }

    $this->delete(route('profile.connected-accounts.destroy', ['provider' => 'google']))->assertTooManyRequests();
});
