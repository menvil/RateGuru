<?php

use App\Enums\SocialProvider;
use App\Enums\UserStatus;
use App\Models\PasswordResetToken;
use App\Models\Session;
use App\Models\SocialAccount;
use App\Models\User;
use App\Support\Auth\SessionGeneration;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

/*
 * A provider that has confirmed an email address signs straight into the
 * existing account that uses it, and the provider is linked on the way.
 *
 * If the account's own email was confirmed, it is the same person signing
 * in another way: nothing else changes. If it was never confirmed, whoever
 * created the account never proved they own the mailbox, so the owner takes
 * it over and everything its creator could still use is revoked.
 */

/** A provider response that confirms its address, for either provider. */
function confirmedIdentity(string $provider, string $email, string $subject = 'subject-42'): Laravel\Socialite\Two\User
{
    return fakeSocialiteUser(['id' => $subject, 'email' => $email] + ($provider === 'google' ? ['email_verified' => true] : []));
}

it('signs a confirmed account straight in and links the provider, keeping its password', function (string $provider) {
    Event::fake([Registered::class]);
    $user = User::factory()->create(['email' => 'ivan@example.com']);
    $passwordHash = $user->password;
    Socialite::fake($provider, confirmedIdentity($provider, 'Ivan@Example.com'));

    $this->get(socialCallbackUrl($provider))->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
    $account = SocialAccount::query()->sole();
    $fresh = $user->fresh();

    expect($account->user_id)->toBe($user->id)
        ->and($account->provider)->toBe(SocialProvider::from($provider))
        ->and($account->provider_user_id)->toBe('subject-42')
        ->and(User::count())->toBe(1)
        ->and($fresh->password)->toBe($passwordHash)
        ->and($fresh->session_generation)->toBeNull()
        ->and(session()->has('auth.pending_social_link'))->toBeFalse();

    Event::assertNotDispatched(Registered::class);

    // And the password still works: two ways in now.
    $this->post('/logout');
    $this->post('/login', ['email' => 'ivan@example.com', 'password' => 'password']);
    $this->assertAuthenticatedAs($user);
})->with(['google', 'facebook']);

it('signs an account created through Google straight in through Facebook', function () {
    $user = User::factory()->withoutPassword()->create(['email' => 'ivan@gmail.com']);
    SocialAccount::factory()->for($user)->google()->create(['provider_user_id' => 'g-1']);
    Socialite::fake('facebook', confirmedIdentity('facebook', 'ivan@gmail.com', 'fb-1'));

    $this->get(socialCallbackUrl('facebook'))->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
    expect($user->socialAccounts()->pluck('provider_user_id', 'provider')->all())
        ->toBe(['facebook' => 'fb-1', 'google' => 'g-1']);

    // Both providers now sign the same account in directly.
    foreach (['google' => 'g-1', 'facebook' => 'fb-1'] as $provider => $subject) {
        $this->post('/logout');
        Socialite::fake($provider, confirmedIdentity($provider, 'ivan@gmail.com', $subject));
        $this->get(socialCallbackUrl($provider));
        $this->assertAuthenticatedAs($user);
    }

    expect(User::count())->toBe(1)->and(SocialAccount::count())->toBe(2);
});

it('takes over an unconfirmed account, revoking everything its creator could still use', function (string $provider) {
    $squatter = User::factory()->unverified()->create(['email' => 'ivan@example.com']);
    $rememberToken = $squatter->remember_token;
    Session::create([
        'id' => Str::random(40),
        'user_id' => $squatter->id,
        'ip_address' => '10.0.0.1',
        'user_agent' => 'squatter',
        'payload' => base64_encode('squatter'),
        'last_activity' => now()->getTimestamp(),
    ]);
    PasswordResetToken::create(['email' => 'ivan@example.com', 'token' => Hash::make('reset'), 'created_at' => now()]);
    Socialite::fake($provider, confirmedIdentity($provider, 'ivan@example.com'));

    $response = $this->get(socialCallbackUrl($provider));

    $response->assertRedirect(route('dashboard', absolute: false));
    $response->assertSessionHas('toast.message', trans('auth.social.password_removed', ['provider' => SocialProvider::from($provider)->label()]));
    $this->assertAuthenticatedAs($squatter);

    $fresh = $squatter->fresh();
    expect($fresh->email_verified_at)->not->toBeNull()
        ->and($fresh->password)->toBeNull()
        ->and($fresh->remember_token)->not->toBe($rememberToken)
        ->and($fresh->session_generation)->not->toBeNull()
        ->and(Session::query()->where('user_id', $squatter->id)->exists())->toBeFalse()
        ->and(PasswordResetToken::query()->where('email', 'ivan@example.com')->exists())->toBeFalse()
        ->and(SocialAccount::query()->where('user_id', $squatter->id)->sole()->provider)->toBe(SocialProvider::from($provider))
        ->and(User::count())->toBe(1);

    // The owner's own session is of the new generation and stays signed in.
    $this->get(route('feed'))->assertOk();
    $this->assertAuthenticatedAs($squatter);

    // The password the account's creator chose no longer opens it.
    $this->post('/logout');
    $this->post('/login', ['email' => 'ivan@example.com', 'password' => 'password'])
        ->assertSessionHasErrors(['email' => trans('auth.failed')]);
    $this->assertGuest();
})->with(['google', 'facebook']);

it('ends the session of whoever was signed in to the unconfirmed account before', function () {
    $squatter = User::factory()->unverified()->create(['email' => 'ivan@example.com']);

    // The creator's session, from before the takeover.
    $this->post('/login', ['email' => 'ivan@example.com', 'password' => 'password']);
    $this->assertAuthenticatedAs($squatter);
    $creatorSession = session()->all();

    // The owner takes the account over, in another browser.
    app('auth')->forgetGuards();
    $this->flushSession();
    Socialite::fake('google', confirmedIdentity('google', 'ivan@example.com'));
    $this->get(socialCallbackUrl('google'));

    // The creator's browser comes back with its old session.
    app('auth')->forgetGuards();
    $this->flushSession();
    $this->withSession($creatorSession)
        ->get(route('feed'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', trans('auth.session_ended'));

    $this->assertGuest();
});

it('confirms an unconfirmed account that never had a password without reporting a password removal', function () {
    // No password, so nothing is reported as removed — but "no password" is NOT
    // a reason to trust what is already attached. See the claim-revocation cases
    // below for what decides that.
    $user = User::factory()->unverified()->withoutPassword()->create(['email' => 'ivan@example.com']);
    SocialAccount::factory()->for($user)->facebook()->verifiedEmail('ivan@example.com')->create(['provider_user_id' => 'fb-1']);
    Socialite::fake('google', confirmedIdentity('google', 'ivan@example.com', 'g-1'));

    $this->get(socialCallbackUrl('google'))
        ->assertRedirect(route('dashboard', absolute: false))
        ->assertSessionMissing('toast');

    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->email_verified_at)->not->toBeNull()
        // Kept, because that Facebook link can prove it owns this very address.
        ->and($user->socialAccounts()->count())->toBe(2);
});

/*
 * What happens to a sign-in method that was already on an unconfirmed account
 * when somebody else proves they own its address.
 *
 * The old rule asked whether a password had been removed, on the reasoning that
 * a passwordless unconfirmed account was created through a provider and its link
 * was probably the same person. That is exactly backwards:
 * RegisterSocialUserAction creates an account from an address a provider has NOT
 * confirmed — which is why the account is unconfirmed at all — and stores the
 * link anyway. So the rule treated "nobody proved this address" as proof.
 *
 * It is evidence now: a link survives only if its own provider confirmed the
 * same address.
 */
it('revokes a prior provider link that cannot prove it owns the claimed address', function (
    string $state,
    string $linkEmail,
    bool $survives,
) {
    $user = User::factory()->unverified()->withoutPassword()->create(['email' => 'ivan@example.com']);

    $link = SocialAccount::factory()->for($user)->facebook();
    $link = match ($state) {
        'verified' => $link->verifiedEmail($linkEmail),
        'unverified' => $link->unverifiedEmail($linkEmail),
        'legacy' => $link->legacyUnknownVerification($linkEmail),
    };
    $prior = $link->create(['provider_user_id' => 'fb-1']);

    Socialite::fake('google', confirmedIdentity('google', 'ivan@example.com', 'g-1'));

    $this->get(socialCallbackUrl('google'))->assertRedirect(route('dashboard', absolute: false));

    expect($user->fresh()->email_verified_at)->not->toBeNull();

    expect(SocialAccount::query()->whereKey($prior->getKey())->exists())
        ->toBe($survives, "the prior {$state} link to {$linkEmail} should ".($survives ? 'survive' : 'be revoked'));

    // Whatever happened to the old one, the identity that just proved the
    // address is attached.
    expect(SocialAccount::query()->where('user_id', $user->id)->where('provider_user_id', 'g-1')->exists())
        ->toBeTrue('the claiming identity must remain');
})->with([
    'provider confirmed the same address' => ['verified', 'ivan@example.com', true],
    'provider confirmed a different address' => ['verified', 'someone-else@example.com', false],
    'provider explicitly did not confirm it' => ['unverified', 'ivan@example.com', false],
    'written before the proof was recorded' => ['legacy', 'ivan@example.com', false],
]);

it('revokes a prior link that records no address at all', function () {
    $user = User::factory()->unverified()->withoutPassword()->create(['email' => 'ivan@example.com']);
    $prior = SocialAccount::factory()->for($user)->facebook()->create([
        'provider_user_id' => 'fb-1',
        'provider_email' => null,
        'provider_email_verified' => null,
    ]);

    Socialite::fake('google', confirmedIdentity('google', 'ivan@example.com', 'g-1'));
    $this->get(socialCallbackUrl('google'))->assertRedirect(route('dashboard', absolute: false));

    expect(SocialAccount::query()->whereKey($prior->getKey())->exists())->toBeFalse();
});

it('revokes an untrusted prior link in the password-takeover case too', function () {
    // The takeover path already removed every link. It must keep doing so for the
    // ones that cannot prove the address, and — since the rule is now about proof
    // rather than about the password — keep a provably-owned one.
    $user = User::factory()->unverified()->create(['email' => 'ivan@example.com']);
    $untrusted = SocialAccount::factory()->for($user)->facebook()->unverifiedEmail('ivan@example.com')->create(['provider_user_id' => 'fb-1']);

    PasswordResetToken::create(['email' => 'ivan@example.com', 'token' => Hash::make('reset'), 'created_at' => now()]);

    Socialite::fake('google', confirmedIdentity('google', 'ivan@example.com', 'g-1'));
    $this->get(socialCallbackUrl('google'))->assertRedirect(route('dashboard', absolute: false));

    $fresh = $user->fresh();
    expect($fresh->password)->toBeNull()
        ->and($fresh->email_verified_at)->not->toBeNull()
        ->and($fresh->session_generation)->not->toBeNull()
        ->and(PasswordResetToken::query()->where('email', 'ivan@example.com')->exists())->toBeFalse()
        ->and(SocialAccount::query()->whereKey($untrusted->getKey())->exists())->toBeFalse();
});

it('records whether the provider confirmed the address it stores', function () {
    // The proof the rule above reads has to be written in the first place, and
    // written about the address actually stored.
    Socialite::fake('google', fakeSocialiteUser(['id' => 'g-1', 'email' => 'ivan@company.test']));

    $this->get(socialCallbackUrl('google'))->assertRedirect(route('dashboard', absolute: false));

    $link = SocialAccount::query()->sole();

    expect($link->provider_email)->toBe('ivan@company.test')
        // Google did not vouch: not a gmail address and no email_verified claim.
        ->and($link->provider_email_verified)->toBeFalse()
        ->and($link->user->email_verified_at)->toBeNull();
});

it('changes nothing when the account already holds another identity of that provider', function () {
    $user = User::factory()->unverified()->create(['email' => 'ivan@example.com']);
    $passwordHash = $user->password;
    SocialAccount::factory()->for($user)->google()->create(['provider_user_id' => 'g-old']);
    Socialite::fake('google', confirmedIdentity('google', 'ivan@example.com', 'g-new'));

    $response = $this->get(socialCallbackUrl('google'));

    $response->assertRedirect(route('login'));
    $response->assertSessionHasErrors(['social' => trans('auth.social.provider_already_linked', ['provider' => 'Google'])]);
    $this->assertGuest();

    // Refused as a whole: the account was not secured either.
    $fresh = $user->fresh();
    expect($fresh->email_verified_at)->toBeNull()
        ->and($fresh->password)->toBe($passwordHash)
        ->and($fresh->session_generation)->toBeNull()
        ->and($user->socialAccounts()->pluck('provider_user_id')->all())->toBe(['g-old']);
});

it('never lets a confirmed email take over a deleted account', function (string $provider) {
    User::factory()->tombstoned()->create(['email' => 'ivan@example.com']);
    Socialite::fake($provider, confirmedIdentity($provider, 'ivan@example.com'));

    $this->get(socialCallbackUrl($provider))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['social' => trans('auth.failed')]);

    $this->assertGuest();
    expect(SocialAccount::count())->toBe(0);
})->with(['google', 'facebook']);

it('still signs living sanctioned accounts in', function (string $state) {
    $user = User::factory()->{$state}()->create(['email' => 'ivan@example.com']);
    Socialite::fake('facebook', confirmedIdentity('facebook', 'ivan@example.com'));

    $this->get(socialCallbackUrl('facebook'))->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->status)->toBe(UserStatus::from($state));
})->with(['limited', 'banned', 'shadowbanned']);

it('returns a claim that started in the modal to its page, with the notice', function () {
    User::factory()->unverified()->create(['email' => 'ivan@example.com']);
    Socialite::fake('facebook');
    $this->get('/auth/facebook?'.http_build_query(authModalFields('login', '/posts/5')));
    Socialite::fake('facebook', confirmedIdentity('facebook', 'ivan@example.com'));

    $this->get(socialCallbackUrl('facebook'))
        ->assertRedirect('/posts/5')
        ->assertSessionHas('toast.message');

    $this->assertAuthenticated();
});

it('shows the notice once, on the next page', function () {
    User::factory()->unverified()->create(['email' => 'ivan@example.com']);
    Socialite::fake('google', confirmedIdentity('google', 'ivan@example.com'));
    $this->get(socialCallbackUrl('google'));

    $first = $this->get(route('feed'))->assertOk()->getContent();

    expect($first)->toContain("\$dispatch('toast'")
        ->toContain('Your email is confirmed through Google');

    $second = $this->get(route('feed'))->assertOk()->getContent();

    expect($second)->not->toContain("\$dispatch('toast'")
        ->not->toContain('Your email is confirmed through Google');
});

it('leaves accounts that never started a new session generation alone', function () {
    $user = User::factory()->create();

    // A session that predates the mechanism carries no generation at all.
    $this->actingAs($user)->get(route('feed'))->assertOk();
    $this->assertAuthenticatedAs($user);
});

it('ends a session of an older generation, and keeps one of the current', function () {
    $user = User::factory()->create(['session_generation' => 'current-generation']);

    $this->actingAs($user)
        ->withSession([SessionGeneration::SESSION_KEY => 'current-generation'])
        ->get(route('feed'))
        ->assertOk();
    $this->assertAuthenticatedAs($user);

    $this->actingAs($user)
        ->withSession([SessionGeneration::SESSION_KEY => 'older-generation'])
        ->get(route('feed'))
        ->assertRedirect(route('login'));
    $this->assertGuest();
});

it('stamps every new sign-in with the account generation', function () {
    User::factory()->create(['email' => 'ivan@example.com', 'session_generation' => 'current-generation']);

    $this->post('/login', ['email' => 'ivan@example.com', 'password' => 'password']);

    expect(session(SessionGeneration::SESSION_KEY))->toBe('current-generation');
    $this->get(route('feed'))->assertOk();
    $this->assertAuthenticated();
});
