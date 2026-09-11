<?php

use App\Actions\Users\GenerateUniqueUsernameAction;
use App\Enums\SocialProvider;
use App\Enums\UserStatus;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;

/*
 * The callback half of the round trip for a guest: a first-time identity
 * becomes an account, a known identity signs its account in. Socialite is
 * faked throughout — no provider is ever contacted.
 */

it('creates a user and a social account for a first-time identity', function (string $provider) {
    Socialite::fake($provider, fakeSocialiteUser([
        'id' => 'subject-42',
        'name' => 'Ivan Moroz',
        'email' => 'ivan@example.com',
    ]));

    $response = $this->get(socialCallbackUrl($provider));

    $response->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticated();

    $user = User::query()->where('email', 'ivan@example.com')->sole();
    expect($user->name)->toBe('Ivan Moroz')
        ->and($user->username)->toBe('ivan_moroz')
        ->and($user->password)->toBeNull()
        ->and($user->status)->toBe(UserStatus::Active);

    $this->assertDatabaseHas('users', ['email' => 'ivan@example.com', 'password' => null]);

    $account = SocialAccount::query()->sole();
    expect($account->user_id)->toBe($user->id)
        ->and($account->provider)->toBe(SocialProvider::from($provider))
        ->and($account->provider_user_id)->toBe('subject-42');
})->with(['google', 'facebook']);

it('generates the username through the existing generator, stepping past taken handles', function () {
    User::factory()->create(['username' => 'ivan_moroz']);
    Socialite::fake('google', fakeSocialiteUser(['name' => 'Ivan Moroz', 'email' => 'ivan@example.com']));

    $this->get(socialCallbackUrl('google'));

    expect(User::query()->where('email', 'ivan@example.com')->sole()->username)->toBe('ivan_moroz_2');
});

it('falls back from the provider name to the nickname and then to the email local part', function (array $attributes, string $name, string $username) {
    Socialite::fake('google', fakeSocialiteUser($attributes));

    $this->get(socialCallbackUrl('google'));

    $user = User::query()->where('email', Str::lower(trim($attributes['email'])))->sole();
    expect($user->name)->toBe($name)->and($user->username)->toBe($username);
})->with([
    'blank name, nickname present' => [['name' => '  ', 'nickname' => 'moroz', 'email' => 'ivan.m@example.com'], 'moroz', 'moroz'],
    'no name, no nickname' => [['name' => null, 'nickname' => null, 'email' => 'Chef.Ivan@Example.com'], 'chef.ivan', 'chef_ivan'],
]);

it('captures the locale the person signed up in, like a typed registration', function () {
    Socialite::fake('google', fakeSocialiteUser());

    $this->withSession(['locale' => 'ru'])->get(socialCallbackUrl('google'));

    expect(User::query()->where('email', 'ivan@example.com')->sole()->locale)->toBe('ru');
});

it('fires the Registered event for a new social user only', function () {
    Event::fake([Registered::class]);
    Socialite::fake('google', fakeSocialiteUser(['id' => 'g-1']));

    $this->get(socialCallbackUrl('google'));
    Event::assertDispatchedTimes(Registered::class, 1);

    $this->post('/logout');
    $this->assertGuest();

    Socialite::fake('google', fakeSocialiteUser(['id' => 'g-1']));
    $this->get(socialCallbackUrl('google'));

    Event::assertDispatchedTimes(Registered::class, 1);
    $this->assertAuthenticated();
    expect(User::count())->toBe(1)->and(SocialAccount::count())->toBe(1);
});

it('signs a linked identity in without creating another user or account', function (string $provider) {
    $user = User::factory()->create(['email' => 'ivan@example.com']);
    SocialAccount::factory()->for($user)->create([
        'provider' => SocialProvider::from($provider),
        'provider_user_id' => 'subject-42',
    ]);

    // The provider email is not the identity: even a changed address at the
    // provider still resolves to the linked account.
    Socialite::fake($provider, fakeSocialiteUser(['id' => 'subject-42', 'email' => 'renamed@example.com']));

    $this->get(socialCallbackUrl($provider))->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
    expect(User::count())->toBe(1)
        ->and(SocialAccount::count())->toBe(1)
        ->and($user->fresh()->email)->toBe('ivan@example.com');
})->with(['google', 'facebook']);

it('never resolves an unknown identity by email alone', function () {
    $user = User::factory()->create(['email' => 'ivan@example.com']);
    SocialAccount::factory()->for($user)->google()->create(['provider_user_id' => 'g-1']);

    Socialite::fake('google', fakeSocialiteUser(['id' => 'g-2', 'email' => 'ivan@example.com']));

    $this->get(socialCallbackUrl('google'))->assertRedirect(route('login'));

    $this->assertGuest();
    expect(SocialAccount::count())->toBe(1);
});

it('refuses a tombstoned account through Google or Facebook with the generic failure', function (string $provider) {
    $tombstone = User::factory()->tombstoned()->create();
    SocialAccount::factory()->for($tombstone)->create([
        'provider' => SocialProvider::from($provider),
        'provider_user_id' => 'subject-42',
    ]);
    Socialite::fake($provider, fakeSocialiteUser(['id' => 'subject-42', 'email' => $tombstone->email]));

    $response = $this->get(socialCallbackUrl($provider));

    $response->assertRedirect(route('login'));
    $response->assertSessionHasErrors(['social' => trans('auth.failed')]);
    $this->assertGuest();
    expect(User::count())->toBe(1)->and(SocialAccount::count())->toBe(1);
})->with(['google', 'facebook']);

it('refuses to register an identity whose email belongs to a tombstone', function () {
    User::factory()->tombstoned()->create(['email' => 'ivan@example.com']);
    Socialite::fake('google', fakeSocialiteUser(['email' => 'ivan@example.com']));

    $response = $this->get(socialCallbackUrl('google'));

    $response->assertRedirect(route('login'));
    $response->assertSessionHasErrors(['social' => trans('auth.failed')]);
    $this->assertGuest();
    expect(User::count())->toBe(1)
        ->and(SocialAccount::count())->toBe(0)
        ->and(session()->has('auth.pending_social_link'))->toBeFalse();
});

it('still signs living sanctioned accounts in, matching password login semantics', function (string $state) {
    $user = User::factory()->{$state}()->create();
    SocialAccount::factory()->for($user)->google()->create(['provider_user_id' => 'g-1']);
    Socialite::fake('google', fakeSocialiteUser(['id' => 'g-1', 'email' => $user->email]));

    $this->get(socialCallbackUrl('google'))->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
})->with(['limited', 'banned', 'shadowbanned']);

it('normalizes the provider email like a typed registration', function () {
    Socialite::fake('google', fakeSocialiteUser(['email' => '  Ivan.Moroz@Example.COM ']));

    $this->get(socialCallbackUrl('google'));

    // Compare the stored bytes rather than probing with a mixed-case lookup:
    // MariaDB's default collation would match either spelling and prove nothing.
    expect(User::query()->sole()->email)->toBe('ivan.moroz@example.com');
});

it('creates neither a user nor a social account when the provider shares no email', function (string $provider, ?string $email) {
    Socialite::fake($provider, fakeSocialiteUser(['email' => $email]));

    $response = $this->get(socialCallbackUrl($provider));

    $response->assertRedirect(route('login'));
    $response->assertSessionHasErrors([
        'social' => trans('auth.social.email_missing', ['provider' => SocialProvider::from($provider)->label()]),
    ]);
    $this->assertGuest();
    expect(User::count())->toBe(0)->and(SocialAccount::count())->toBe(0);
})->with(['google', 'facebook'])->with([null, '', '   ']);

it('trusts Google for a gmail.com address', function () {
    Socialite::fake('google', fakeSocialiteUser(['email' => 'Someone@Gmail.com']));

    $this->get(socialCallbackUrl('google'));

    expect(User::query()->where('email', 'someone@gmail.com')->sole()->email_verified_at)->not->toBeNull();
});

it('trusts Google for a Workspace address with an email_verified claim and a domain', function () {
    Socialite::fake('google', fakeSocialiteUser([
        'email' => 'me@corp.example',
        'email_verified' => true,
        'hd' => 'corp.example',
    ]));

    $this->get(socialCallbackUrl('google'));

    expect(User::query()->where('email', 'me@corp.example')->sole()->email_verified_at)->not->toBeNull();
});

it('does not trust Google for a third-party address without an authoritative domain', function (array $claims) {
    Notification::fake();
    Socialite::fake('google', fakeSocialiteUser(array_merge(['email' => 'me@outlook.example'], $claims)));

    $this->get(socialCallbackUrl('google'));

    $user = User::query()->where('email', 'me@outlook.example')->sole();
    expect($user->email_verified_at)->toBeNull();
    Notification::assertSentTo($user, VerifyEmail::class);
})->with([
    'no claims at all' => [[]],
    'verified without a domain' => [['email_verified' => true]],
    'domain without verified' => [['hd' => 'outlook.example']],
    'verified with a blank domain' => [['email_verified' => true, 'hd' => '   ']],
    'verified as a string' => [['email_verified' => 'true', 'hd' => 'outlook.example']],
]);

it('never trusts Facebook with email verification, even for a gmail.com address', function () {
    Notification::fake();
    Socialite::fake('facebook', fakeSocialiteUser(['email' => 'someone@gmail.com', 'email_verified' => true]));

    $this->get(socialCallbackUrl('facebook'));

    $user = User::query()->where('email', 'someone@gmail.com')->sole();
    expect($user->email_verified_at)->toBeNull();
    Notification::assertSentTo($user, VerifyEmail::class);
});

it('regenerates the session id on social login', function () {
    $this->withSession(['seed' => 'value']);
    $before = session()->getId();
    Socialite::fake('google', fakeSocialiteUser());

    $this->get(socialCallbackUrl('google'));

    $this->assertAuthenticated();
    expect(session()->getId())->not->toBe($before);
});

it('honours the intended destination after social login', function () {
    $this->withSession(['url.intended' => '/saved']);
    Socialite::fake('google', fakeSocialiteUser());

    $this->get(socialCallbackUrl('google'))->assertRedirect('/saved');
});

it('returns to login with a generic message when the person cancels at the provider', function (string $provider) {
    Socialite::fake($provider, fn () => throw new LogicException('Socialite must not be asked for a user after a cancelled consent'));

    $response = $this->get(socialCallbackUrl($provider, ['code' => null, 'error' => 'access_denied']));

    $response->assertRedirect(route('login'));
    $response->assertSessionHasErrors([
        'social' => trans('auth.social.cancelled', ['provider' => SocialProvider::from($provider)->label()]),
    ]);
    $this->assertGuest();
    expect(User::count())->toBe(0);
})->with(['google', 'facebook']);

it('returns to login with a generic message when the provider refuses', function () {
    Socialite::fake('google', fn () => throw new LogicException('Socialite must not be asked for a user after a provider error'));

    $response = $this->get(socialCallbackUrl('google', ['code' => null, 'error' => 'server_error']));

    $response->assertRedirect(route('login'));
    $response->assertSessionHasErrors(['social' => trans('auth.social.failed', ['provider' => 'Google'])]);
    $this->assertGuest();
});

it('returns to login with a generic message when the callback carries no authorization code', function () {
    Socialite::fake('google', fn () => throw new LogicException('Socialite must not be asked for a user without a code'));

    $response = $this->get(socialCallbackUrl('google', ['code' => null]));

    $response->assertRedirect(route('login'));
    $response->assertSessionHasErrors(['social' => trans('auth.social.failed', ['provider' => 'Google'])]);
    $this->assertGuest();
});

it('returns to login with a generic message when the OAuth state is invalid or expired', function () {
    Socialite::fake('facebook', fn () => throw new InvalidStateException);

    $response = $this->get(socialCallbackUrl('facebook'));

    $response->assertRedirect(route('login'));
    $response->assertSessionHasErrors(['social' => trans('auth.social.expired', ['provider' => 'Facebook'])]);
    $this->assertGuest();
});

it('renders the generic message on the login page without leaking anything else', function () {
    Socialite::fake('google', fakeSocialiteUser(['email' => null]));

    $this->get(socialCallbackUrl('google'));
    $response = $this->get('/login');

    $response->assertOk()
        ->assertSee(trans('auth.social.email_missing', ['provider' => 'Google']))
        ->assertDontSee('provider-user-1')
        ->assertDontSee('fake-token');
});

it('lets unexpected failures reach the exception handler instead of a silent login failure', function () {
    Socialite::fake('google', fn () => throw new RuntimeException('token endpoint exploded'));
    $this->withoutExceptionHandling();

    expect(fn () => $this->get(socialCallbackUrl('google')))
        ->toThrow(RuntimeException::class, 'token endpoint exploded');

    $this->assertGuest();
});

it('signs in to the winner when the callback loses a race to register the same identity', function () {
    Socialite::fake('google', fakeSocialiteUser(['id' => 'subject-42', 'email' => 'ivan@example.com']));

    // The concurrent callback commits between this callback's lookups and
    // its insert — modelled at the one seam registration already exposes to
    // tests, the username generator, so the real transaction, the real
    // unique index and the real re-read are all exercised.
    $winner = null;
    $generator = Mockery::mock(GenerateUniqueUsernameAction::class);
    $generator->shouldReceive('handle')->once()->andReturnUsing(function () use (&$winner): string {
        $winner = User::factory()->create(['email' => 'ivan@example.com']);
        SocialAccount::factory()->for($winner)->google()->create(['provider_user_id' => 'subject-42']);

        return 'ivan_moroz';
    });
    app()->instance(GenerateUniqueUsernameAction::class, $generator);

    $this->get(socialCallbackUrl('google'))->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($winner);
    expect(User::count())->toBe(1)->and(SocialAccount::count())->toBe(1);
});

it('parks a pending link when the callback loses a race to a registration with the same email', function () {
    Socialite::fake('google', fakeSocialiteUser(['email' => 'ivan@example.com']));

    $generator = Mockery::mock(GenerateUniqueUsernameAction::class);
    $generator->shouldReceive('handle')->once()->andReturnUsing(function (): string {
        // A typed registration claims the email first.
        User::factory()->create(['email' => 'ivan@example.com']);

        return 'ivan_moroz';
    });
    app()->instance(GenerateUniqueUsernameAction::class, $generator);

    $response = $this->get(socialCallbackUrl('google'));

    $response->assertRedirect(route('login'));
    $this->assertGuest();
    expect(User::count())->toBe(1)
        ->and(SocialAccount::count())->toBe(0)
        ->and(session('auth.pending_social_link.email'))->toBe('ivan@example.com');
});
