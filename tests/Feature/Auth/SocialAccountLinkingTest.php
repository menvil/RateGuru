<?php

use App\Data\Auth\PendingSocialLink;
use App\Enums\SocialProvider;
use App\Models\SocialAccount;
use App\Models\User;
use Laravel\Socialite\Facades\Socialite;

/*
 * What happens when a provider identity meets an email that already belongs
 * to an account, and the provider has NOT confirmed that address — Google
 * without an email_verified claim on a non-Gmail address. Never a silent
 * link, never a second user: ownership is proven by signing in to that
 * account, and only then is the identity attached — with every conflict
 * failing closed. (An address the provider did confirm signs straight in:
 * see SocialAccountClaimTest.)
 */

/** @return array{provider: string, provider_user_id: string, email: string, created_at: int} */
function pendingLinkPayload(array $overrides = []): array
{
    return array_merge([
        'provider' => 'google',
        'provider_user_id' => 'g-1',
        'email' => 'ivan@example.com',
        'created_at' => now()->getTimestamp(),
    ], $overrides);
}

it('parks a pending link instead of linking or duplicating when the provider did not confirm the email', function () {
    User::factory()->create(['email' => 'ivan@example.com']);
    Socialite::fake('google', fakeSocialiteUser(['id' => 'subject-42', 'email' => 'Ivan@Example.com']));

    $response = $this->get(socialCallbackUrl('google'));

    $response->assertRedirect(route('login'));
    $response->assertSessionHas('status', trans('auth.social.pending_link', ['provider' => 'Google']));
    $this->assertGuest();
    expect(User::count())->toBe(1)
        ->and(SocialAccount::count())->toBe(0)
        ->and(session(PendingSocialLink::SESSION_KEY))->toMatchArray([
            'provider' => 'google',
            'provider_user_id' => 'subject-42',
            'email' => 'ivan@example.com',
        ]);
});

it('shows the person why they are back on the login page', function () {
    User::factory()->create(['email' => 'ivan@example.com']);
    Socialite::fake('google', fakeSocialiteUser(['email' => 'ivan@example.com']));

    $this->get(socialCallbackUrl('google'));

    $this->get('/login')->assertOk()->assertSee(trans('auth.social.pending_link', ['provider' => 'Google']));
});

it('links the pending identity once the account signs in with its password, and signs in directly afterwards', function () {
    $provider = 'google';
    $existing = User::factory()->create(['email' => 'ivan@example.com']);
    Socialite::fake($provider, fakeSocialiteUser(['id' => 'subject-42', 'email' => 'ivan@example.com']));
    $this->get(socialCallbackUrl($provider));
    $this->assertGuest();

    $this->post('/login', ['email' => 'ivan@example.com', 'password' => 'password'])
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($existing);
    $account = SocialAccount::query()->sole();
    expect($account->user_id)->toBe($existing->id)
        ->and($account->provider)->toBe(SocialProvider::from($provider))
        ->and($account->provider_user_id)->toBe('subject-42')
        ->and(session()->has(PendingSocialLink::SESSION_KEY))->toBeFalse();

    // From now on the provider signs the person straight in.
    $this->post('/logout');
    $this->assertGuest();
    Socialite::fake($provider, fakeSocialiteUser(['id' => 'subject-42', 'email' => 'ivan@example.com']));

    $this->get(socialCallbackUrl($provider))->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($existing);
    expect(User::count())->toBe(1)->and(SocialAccount::count())->toBe(1);
});

it('completes a pending Google link through an existing Facebook sign-in of a social-only account', function () {
    $user = User::factory()->withoutPassword()->create(['email' => 'ivan@example.com']);
    SocialAccount::factory()->for($user)->facebook()->create(['provider_user_id' => 'fb-1']);

    // Google without a confirmation of this non-Gmail address: pending.
    Socialite::fake('google', fakeSocialiteUser(['id' => 'g-1', 'email' => 'ivan@example.com']));
    $this->get(socialCallbackUrl('google'))->assertRedirect(route('login'));
    $this->assertGuest();
    expect(SocialAccount::count())->toBe(1);

    Socialite::fake('facebook', fakeSocialiteUser(['id' => 'fb-1', 'email' => 'ivan@example.com']));
    $this->get(socialCallbackUrl('facebook'))->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
    expect(User::count())->toBe(1)
        ->and($user->fresh()->password)->toBeNull()
        ->and(SocialAccount::query()->where('user_id', $user->id)->where('provider', 'google')->sole()->provider_user_id)->toBe('g-1')
        ->and(SocialAccount::query()->where('user_id', $user->id)->where('provider', 'facebook')->sole()->provider_user_id)->toBe('fb-1')
        ->and(session()->has(PendingSocialLink::SESSION_KEY))->toBeFalse();
});

it('keeps only the newest pending link when a second identity collides before the first is claimed', function () {
    User::factory()->create(['email' => 'ivan@example.com']);

    Socialite::fake('google', fakeSocialiteUser(['id' => 'g-1', 'email' => 'ivan@example.com']));
    $this->get(socialCallbackUrl('google'));
    Socialite::fake('google', fakeSocialiteUser(['id' => 'g-2', 'email' => 'ivan@example.com']));
    $this->get(socialCallbackUrl('google'));

    expect(session(PendingSocialLink::SESSION_KEY))->toMatchArray(['provider' => 'google', 'provider_user_id' => 'g-2']);
});

it('never reassigns an identity that already belongs to another account', function () {
    $owner = User::factory()->create();
    SocialAccount::factory()->for($owner)->google()->create(['provider_user_id' => 'g-1']);
    $other = User::factory()->create(['email' => 'other@example.com']);
    Socialite::fake('google', fakeSocialiteUser(['id' => 'g-1', 'email' => 'other@example.com']));

    $response = $this->actingAs($other)->get(socialCallbackUrl('google'));

    $response->assertRedirect(connectedAccountsUrl());
    $response->assertSessionHasErrors(['social' => trans('auth.social.already_linked', ['provider' => 'Google'])], null, 'connectedAccounts');
    expect(SocialAccount::query()->where('provider_user_id', 'g-1')->sole()->user_id)->toBe($owner->id)
        ->and(SocialAccount::count())->toBe(1);
});

it('never lets a pending link move an identity that meanwhile belongs to another account', function () {
    $owner = User::factory()->create();
    SocialAccount::factory()->for($owner)->google()->create(['provider_user_id' => 'g-1']);
    $claimant = User::factory()->create(['email' => 'ivan@example.com']);

    $this->withSession([PendingSocialLink::SESSION_KEY => pendingLinkPayload()])
        ->post('/login', ['email' => 'ivan@example.com', 'password' => 'password']);

    $this->assertAuthenticatedAs($claimant);
    expect(SocialAccount::query()->where('provider_user_id', 'g-1')->sole()->user_id)->toBe($owner->id)
        ->and(SocialAccount::count())->toBe(1)
        ->and(session()->has(PendingSocialLink::SESSION_KEY))->toBeFalse();
});

it('never silently replaces an existing Google identity with another one', function () {
    $user = User::factory()->create(['email' => 'ivan@example.com']);
    SocialAccount::factory()->for($user)->google()->create(['provider_user_id' => 'g-1']);
    Socialite::fake('google', fakeSocialiteUser(['id' => 'g-2', 'email' => 'ivan@example.com']));

    $response = $this->actingAs($user)->get(socialCallbackUrl('google'));

    $response->assertRedirect(connectedAccountsUrl());
    $response->assertSessionHasErrors(['social' => trans('auth.social.provider_already_linked', ['provider' => 'Google'])], null, 'connectedAccounts');
    expect(SocialAccount::query()->where('user_id', $user->id)->sole()->provider_user_id)->toBe('g-1');
});

it('never lets a pending link replace an existing identity of the same provider', function () {
    $user = User::factory()->create(['email' => 'ivan@example.com']);
    SocialAccount::factory()->for($user)->google()->create(['provider_user_id' => 'g-1']);

    $this->withSession([PendingSocialLink::SESSION_KEY => pendingLinkPayload(['provider_user_id' => 'g-2'])])
        ->post('/login', ['email' => 'ivan@example.com', 'password' => 'password']);

    $this->assertAuthenticatedAs($user);
    expect(SocialAccount::query()->where('user_id', $user->id)->sole()->provider_user_id)->toBe('g-1')
        ->and(session()->has(PendingSocialLink::SESSION_KEY))->toBeFalse();
});

it('connects a provider directly to the signed-in account when the emails match', function (string $provider) {
    $user = User::factory()->create(['email' => 'ivan@example.com']);
    Socialite::fake($provider, fakeSocialiteUser(['id' => 'subject-42', 'email' => ' Ivan@Example.com ']));

    $this->actingAs($user)->get(socialCallbackUrl($provider))
        ->assertRedirect(connectedAccountsUrl())
        ->assertSessionHasNoErrors();

    $this->assertAuthenticatedAs($user);
    $account = SocialAccount::query()->where('user_id', $user->id)->sole();
    expect($account->provider)->toBe(SocialProvider::from($provider))
        ->and($account->provider_user_id)->toBe('subject-42')
        ->and($account->provider_email)->toBe('ivan@example.com')
        ->and(User::count())->toBe(1);
})->with(['google', 'facebook']);

it('connects a provider account that uses another email address of the signed-in person', function () {
    $user = User::factory()->create(['email' => 'ivan@example.com']);
    Socialite::fake('facebook', fakeSocialiteUser(['id' => 'fb-1', 'email' => 'ivan.personal@example.com']));

    $this->actingAs($user)->get(socialCallbackUrl('facebook'))
        ->assertRedirect(connectedAccountsUrl())
        ->assertSessionHasNoErrors();

    $this->assertAuthenticatedAs($user);
    expect(SocialAccount::query()->where('user_id', $user->id)->sole())
        ->provider_user_id->toBe('fb-1')
        ->provider_email->toBe('ivan.personal@example.com')
        ->and($user->fresh()->email)->toBe('ivan@example.com')
        ->and(User::count())->toBe(1);
});

it('connects a provider account that shares no email to the signed-in account', function () {
    $user = User::factory()->create(['email' => 'ivan@example.com']);
    Socialite::fake('facebook', fakeSocialiteUser(['id' => 'fb-1', 'email' => null]));

    $this->actingAs($user)->get(socialCallbackUrl('facebook'))
        ->assertRedirect(connectedAccountsUrl())
        ->assertSessionHasNoErrors();

    expect(SocialAccount::query()->where('user_id', $user->id)->sole())
        ->provider_user_id->toBe('fb-1')
        ->provider_email->toBeNull();
});

it('refuses to connect a provider account whose email belongs to another account', function (bool $confirmedByProvider) {
    $user = User::factory()->create(['email' => 'ivan@example.com']);
    $other = User::factory()->create(['email' => 'maria@example.com']);
    Socialite::fake('google', fakeSocialiteUser([
        'id' => 'g-1',
        'email' => 'Maria@Example.com',
        'email_verified' => $confirmedByProvider,
    ]));

    $response = $this->actingAs($user)->get(socialCallbackUrl('google'));

    $response->assertRedirect(connectedAccountsUrl());
    $response->assertSessionHasErrors(['social' => trans('auth.social.email_taken', ['provider' => 'Google'])], null, 'connectedAccounts');
    $this->assertAuthenticatedAs($user);
    expect(SocialAccount::count())->toBe(0)
        ->and($other->fresh()->socialAccounts()->count())->toBe(0);
})->with(['confirmed by the provider' => true, 'not confirmed' => false]);

it('treats a repeat connection of an identity the account already holds as a no-op', function () {
    $user = User::factory()->create(['email' => 'ivan@example.com']);
    SocialAccount::factory()->for($user)->google()->create(['provider_user_id' => 'g-1']);
    Socialite::fake('google', fakeSocialiteUser(['id' => 'g-1', 'email' => 'ivan@example.com']));

    $this->actingAs($user)->get(socialCallbackUrl('google'))
        ->assertRedirect(connectedAccountsUrl())
        ->assertSessionHasNoErrors();

    expect(SocialAccount::count())->toBe(1);
});

it('cannot complete a pending link into an account with a different email', function () {
    $other = User::factory()->create(['email' => 'other@example.com']);

    $this->withSession([PendingSocialLink::SESSION_KEY => pendingLinkPayload()])
        ->post('/login', ['email' => 'other@example.com', 'password' => 'password']);

    $this->assertAuthenticatedAs($other);
    expect(SocialAccount::count())->toBe(0)
        ->and(session()->has(PendingSocialLink::SESSION_KEY))->toBeFalse();
});

it('cannot complete an expired pending link', function () {
    User::factory()->create(['email' => 'ivan@example.com']);
    $expired = now()->subMinutes(PendingSocialLink::LIFETIME_MINUTES + 1)->getTimestamp();

    $this->withSession([PendingSocialLink::SESSION_KEY => pendingLinkPayload(['created_at' => $expired])])
        ->post('/login', ['email' => 'ivan@example.com', 'password' => 'password']);

    $this->assertAuthenticated();
    expect(SocialAccount::count())->toBe(0)
        ->and(session()->has(PendingSocialLink::SESSION_KEY))->toBeFalse();
});

it('completes a pending link that is still within its lifetime', function () {
    $user = User::factory()->create(['email' => 'ivan@example.com']);
    $recent = now()->subMinutes(PendingSocialLink::LIFETIME_MINUTES - 1)->getTimestamp();

    $this->withSession([PendingSocialLink::SESSION_KEY => pendingLinkPayload(['created_at' => $recent])])
        ->post('/login', ['email' => 'ivan@example.com', 'password' => 'password']);

    expect(SocialAccount::query()->where('user_id', $user->id)->sole()->provider_user_id)->toBe('g-1');
});

it('expires a pending link left in the session while time passes', function () {
    $user = User::factory()->create(['email' => 'ivan@example.com']);
    Socialite::fake('google', fakeSocialiteUser(['id' => 'g-1', 'email' => 'ivan@example.com']));
    $this->get(socialCallbackUrl('google'));

    $this->travel(PendingSocialLink::LIFETIME_MINUTES + 1)->minutes();
    $this->post('/login', ['email' => 'ivan@example.com', 'password' => 'password']);

    $this->assertAuthenticatedAs($user);
    expect(SocialAccount::count())->toBe(0);
});

it('ignores a malformed pending link payload and still signs the person in', function (mixed $payload) {
    User::factory()->create(['email' => 'ivan@example.com']);

    $this->withSession([PendingSocialLink::SESSION_KEY => $payload])
        ->post('/login', ['email' => 'ivan@example.com', 'password' => 'password']);

    $this->assertAuthenticated();
    expect(SocialAccount::count())->toBe(0)
        ->and(session()->has(PendingSocialLink::SESSION_KEY))->toBeFalse();
})->with([
    'a string' => ['garbage'],
    'an unsupported provider' => [['provider' => 'github', 'provider_user_id' => 'x', 'email' => 'ivan@example.com', 'created_at' => 1]],
    'a missing subject' => [['provider' => 'google', 'email' => 'ivan@example.com', 'created_at' => 1]],
    'a non-integer timestamp' => [['provider' => 'google', 'provider_user_id' => 'g-1', 'email' => 'ivan@example.com', 'created_at' => 'now']],
]);

it('never stores an OAuth token in the pending link', function () {
    User::factory()->create(['email' => 'ivan@example.com']);
    Socialite::fake('google', fakeSocialiteUser(['email' => 'ivan@example.com', 'token' => 'super-secret-token']));

    $this->get(socialCallbackUrl('google'));

    expect(array_keys((array) session(PendingSocialLink::SESSION_KEY)))
        ->toBe(['provider', 'provider_user_id', 'email', 'created_at'])
        ->and(json_encode(session()->all()))->not->toContain('super-secret-token');
});
