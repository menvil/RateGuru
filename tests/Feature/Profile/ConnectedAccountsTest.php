<?php

use App\Models\SocialAccount;
use App\Models\User;
use App\Notifications\SocialAccountDisconnectedNotification;
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

    $this->actingAs($user)->post(route('profile.connected-accounts.store', ['provider' => 'google']));
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

    $response = $this->actingAs($user)->get(socialCallbackUrl('facebook'));

    $this->followRedirects($response)
        ->assertOk()
        ->assertSee('data-testid="connected-accounts-error"', false)
        ->assertSee(e(__('auth.social.email_taken', ['provider' => 'Facebook'])), false);
});

it('brings a signed-in connection back to the card even when an abandoned modal attempt is remembered', function () {
    $user = User::factory()->create(['email' => 'ivan@example.com']);
    Socialite::fake('google', fakeSocialiteUser(['id' => 'g-1', 'email' => 'ivan@example.com']));

    $this->actingAs($user)
        ->withSession(['auth.surface_context' => [
            '_auth_surface' => 'modal',
            '_auth_mode' => 'login',
            '_auth_return_to' => '/about',
        ]])
        ->get(socialCallbackUrl('google'))
        ->assertRedirect(connectedAccountsUrl());

    expect(session()->has('auth.surface_context'))->toBeFalse();
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
