<?php

use App\Actions\Settings\ApplyProjectPresetAction;
use App\Data\Auth\PendingSocialLink;
use App\Filament\Pages\ProjectSettingsPage;
use App\Models\ProjectSettings;
use App\Models\SocialAccount;
use App\Models\User;
use App\Support\Auth\SocialLinkContext;
use App\Support\Settings\ProjectSettingsManager;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Laravel\Socialite\Facades\Socialite;
use Livewire\Livewire;

/*
 * Google and Facebook can be switched off in Project settings, and a
 * provider without keys is off anyway. Off means gone from every button and
 * refused by the server — and never a silent lockout: whoever signed in only
 * with it is told on the login page how to set a password.
 */

/** @param  array<string, bool>  $providers */
function switchSignInProviders(array $providers): void
{
    (ProjectSettings::query()->find(1) ?? ProjectSettings::factory()->create())
        ->update(['sign_in_providers' => $providers]);
    app(ProjectSettingsManager::class)->flush();
}

// The admin switches.

it('offers a switch per provider in Project settings, both on until switched off', function () {
    ProjectSettings::factory()->create();

    Livewire::actingAs(User::factory()->admin()->create())
        ->test(ProjectSettingsPage::class)
        ->assertSee(__('admin.project_settings.sign_in_title'))
        ->assertSee(__('admin.fields.sign_in_provider', ['provider' => 'Google']))
        ->assertSee(__('admin.fields.sign_in_provider', ['provider' => 'Facebook']))
        ->assertSet('data.sign_in_providers.google', true)
        ->assertSet('data.sign_in_providers.facebook', true);
});

it('saves a provider switched off, leaving the other on', function () {
    ProjectSettings::factory()->create();

    Livewire::actingAs(User::factory()->admin()->create())
        ->test(ProjectSettingsPage::class)
        ->set('data.sign_in_providers.facebook', false)
        ->call('save')
        ->assertHasNoErrors();

    expect(ProjectSettings::query()->sole()->sign_in_providers)->toBe(['google' => true, 'facebook' => false]);
});

it('tells the admin how many accounts use each provider and which keys are missing', function () {
    ProjectSettings::factory()->create();
    SocialAccount::factory()->count(2)->google()->create();
    config()->set('services.facebook.client_id', null);

    Livewire::actingAs(User::factory()->admin()->create())
        ->test(ProjectSettingsPage::class)
        ->assertSee(__('admin.project_settings.sign_in_accounts', ['count' => 2]))
        ->assertSee(__('admin.project_settings.sign_in_not_configured', ['keys' => 'FACEBOOK_CLIENT_ID, FACEBOOK_CLIENT_SECRET']))
        ->assertDontSee('GOOGLE_CLIENT_ID');
});

it('keeps the switches when a preset is applied', function () {
    switchSignInProviders(['google' => true, 'facebook' => false]);

    app(ApplyProjectPresetAction::class)->handle('generic', force: true);

    expect(ProjectSettings::query()->sole()->sign_in_providers)->toBe(['google' => true, 'facebook' => false]);
});

// The buttons.

it('hides a switched-off provider from the login page, the registration page and the modal', function (string $path) {
    switchSignInProviders(['facebook' => false]);

    $this->get($path)
        ->assertOk()
        ->assertSee('data-testid="social-google"', false)
        ->assertDontSee('data-testid="social-facebook"', false);
})->with(['/login', '/register', '/about']);

it('hides a provider whose keys are not set, even while it is switched on', function () {
    config()->set('services.google.client_secret', '');

    $this->get('/login')
        ->assertOk()
        ->assertDontSee('data-testid="social-google"', false)
        ->assertSee('data-testid="social-facebook"', false);
});

it('drops the whole social block when no provider is available', function () {
    switchSignInProviders(['google' => false, 'facebook' => false]);

    $this->get('/login')
        ->assertOk()
        ->assertDontSee('data-testid="social-buttons"', false)
        ->assertDontSee('data-testid="auth-divider"', false)
        ->assertSee('data-testid="login-form"', false);
});

// The server.

it('never sends anyone to a switched-off provider', function () {
    switchSignInProviders(['facebook' => false]);

    $response = $this->get('/auth/facebook');

    $response->assertRedirect(route('login'))
        ->assertSessionHasErrors(['social' => __('auth.social.unavailable', ['provider' => 'Facebook'])]);
    expect(session()->has('state'))->toBeFalse();
});

it('brings a modal attempt at a switched-off provider back to the modal', function () {
    switchSignInProviders(['facebook' => false]);

    $this->get('/auth/facebook?'.http_build_query(authModalFields('login', '/about')))
        ->assertRedirect('/about')
        ->assertSessionHasErrors(['social' => __('auth.social.unavailable', ['provider' => 'Facebook'])], null, 'authModal')
        ->assertSessionHas('auth_modal', ['mode' => 'login']);
});

it('signs nobody in through a switched-off provider, not even an account linked to it', function () {
    $user = User::factory()->create();
    SocialAccount::factory()->for($user)->facebook()->create(['provider_user_id' => 'fb-1']);
    Socialite::fake('facebook', fakeSocialiteUser(['id' => 'fb-1', 'email' => $user->email]));
    switchSignInProviders(['facebook' => false]);

    $this->get(socialCallbackUrl('facebook'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['social' => __('auth.social.unavailable', ['provider' => 'Facebook'])]);

    $this->assertGuest();
    expect(User::count())->toBe(1);
});

it('registers nobody through a switched-off provider', function () {
    Socialite::fake('google', fakeSocialiteUser(['id' => 'g-1', 'email' => 'new@gmail.com']));
    config()->set('services.google.client_id', null);

    $this->get(socialCallbackUrl('google'))->assertSessionHasErrors(['social']);

    $this->assertGuest();
    expect(User::count())->toBe(0);
});

it('never starts connecting a switched-off provider', function () {
    $user = User::factory()->create();
    switchSignInProviders(['google' => false]);

    $this->actingAs($user)->post(route('profile.connected-accounts.store', ['provider' => 'google']))
        ->assertRedirect(connectedAccountsUrl())
        ->assertSessionHasErrors(['social' => __('auth.social.unavailable', ['provider' => 'Google'])], null, 'connectedAccounts');

    expect(session()->has(SocialLinkContext::SESSION_KEY))->toBeFalse();
});

it('connects nothing when the provider is switched off during the round trip', function () {
    $user = User::factory()->create();
    Socialite::fake('google', fakeSocialiteUser(['id' => 'g-1', 'email' => 'ivan.personal@gmail.com']));

    startConnectingProvider($user, 'google');
    switchSignInProviders(['google' => false]);

    $this->get(socialCallbackUrl('google'))
        ->assertRedirect(connectedAccountsUrl())
        ->assertSessionHasErrors(['social' => __('auth.social.unavailable', ['provider' => 'Google'])], null, 'connectedAccounts');

    expect(SocialAccount::count())->toBe(0);
});

it('does not complete a pending link for a provider switched off since it was parked', function () {
    User::factory()->create(['email' => 'ivan@example.com']);
    switchSignInProviders(['google' => false]);

    $this->withSession([PendingSocialLink::SESSION_KEY => [
        'provider' => 'google',
        'provider_user_id' => 'g-1',
        'email' => 'ivan@example.com',
        'created_at' => now()->getTimestamp(),
    ]])->post('/login', ['email' => 'ivan@example.com', 'password' => 'password']);

    $this->assertAuthenticated();
    expect(SocialAccount::count())->toBe(0);
});

// Nobody is locked out silently.

it('tells people who signed in with a switched-off provider how to set a password', function (string $path) {
    SocialAccount::factory()->facebook()->create();
    switchSignInProviders(['facebook' => false]);

    $this->get($path)
        ->assertOk()
        ->assertSee('data-testid="social-unavailable-notice"', false)
        ->assertSee(e(__('auth.social.unavailable_notice', ['provider' => 'Facebook'])), false)
        ->assertSee(route('password.request'), false);
})->with(['/login', '/about']);

it('names a provider only when someone signs in with it and only on the login side', function () {
    switchSignInProviders(['facebook' => false]);

    $this->get('/login')->assertOk()->assertDontSee('data-testid="social-unavailable-notice"', false);

    SocialAccount::factory()->facebook()->create();

    $this->get('/register')->assertOk()->assertDontSee('data-testid="social-unavailable-notice"', false);
});

it('says nothing while the provider is available', function () {
    SocialAccount::factory()->facebook()->create();

    $this->get('/login')->assertOk()->assertDontSee('data-testid="social-unavailable-notice"', false);
});

it('lets an account that only ever signed in with a switched-off provider set a password and sign in with it', function () {
    Notification::fake();
    $user = User::factory()->withoutPassword()->create(['email' => 'ivan@example.com']);
    SocialAccount::factory()->for($user)->facebook()->create();
    switchSignInProviders(['facebook' => false]);

    $this->post(route('password.email'), ['email' => 'ivan@example.com'])->assertSessionHasNoErrors();

    $token = null;
    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use (&$token): bool {
        $token = $notification->token;

        return true;
    });

    $this->post(route('password.store'), [
        'token' => $token,
        'email' => 'ivan@example.com',
        'password' => 'a-new-password-123',
        'password_confirmation' => 'a-new-password-123',
    ])->assertSessionHasNoErrors();

    $this->post('/login', ['email' => 'ivan@example.com', 'password' => 'a-new-password-123']);

    $this->assertAuthenticatedAs($user);
});

// The Connected accounts card.

it('keeps listing a connected provider that was switched off, and says so', function () {
    $user = User::factory()->create();
    SocialAccount::factory()->for($user)->facebook()->create(['provider_email' => 'ivan.personal@example.com']);
    switchSignInProviders(['facebook' => false]);

    $this->actingAs($user)->get('/profile')
        ->assertOk()
        ->assertSee('data-testid="connected-account-facebook"', false)
        ->assertSee(__('profile.connected.unavailable', ['provider' => 'Facebook']))
        ->assertSee('data-testid="disconnect-facebook"', false)
        ->assertDontSee('data-testid="connect-facebook"', false);
});

it('does not offer a switched-off provider that is not connected', function () {
    $user = User::factory()->create();
    switchSignInProviders(['facebook' => false]);

    $this->actingAs($user)->get('/profile')
        ->assertOk()
        ->assertSee('data-testid="connected-account-google"', false)
        ->assertDontSee('data-testid="connected-account-facebook"', false);
});

it('hides the card when there is nothing to connect and nothing connected', function () {
    $user = User::factory()->create();
    switchSignInProviders(['google' => false, 'facebook' => false]);

    $this->actingAs($user)->get('/profile')
        ->assertOk()
        ->assertDontSee('id="connected-accounts"', false);
});

it('never counts a switched-off provider as a way in when disconnecting', function () {
    $user = User::factory()->withoutPassword()->create();
    SocialAccount::factory()->for($user)->google()->create();
    SocialAccount::factory()->for($user)->facebook()->create();
    switchSignInProviders(['facebook' => false]);

    $this->actingAs($user)->get('/profile')
        ->assertOk()
        ->assertDontSee('data-testid="disconnect-google"', false)
        ->assertSee('data-testid="disconnect-facebook"', false);

    $this->delete(route('profile.connected-accounts.destroy', ['provider' => 'google']))
        ->assertSessionHasErrors(['social' => __('profile.connected.last_method_error', ['provider' => 'Google'])], null, 'connectedAccounts');

    $this->delete(route('profile.connected-accounts.destroy', ['provider' => 'facebook']))
        ->assertSessionHasNoErrors();

    expect($user->socialAccounts()->pluck('provider')->map->value->all())->toBe(['google']);
});
