<?php

use App\Enums\SocialProvider;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use Laravel\Socialite\Facades\Socialite;

/*
 * Google and Facebook started from the authentication modal. The provider
 * flow itself is untouched — same routes, same actions — and only the way
 * back changes: the page the modal was opened on instead of /login.
 */

/** Starts the provider round trip the way a click in the modal does. */
function startSocialFromModal(string $provider, string $mode, string $page): void
{
    Socialite::fake($provider);

    test()->get('/auth/'.$provider.'?'.http_build_query(authModalFields($mode, $page)))->assertRedirect();
}

it('remembers where the modal was opened only in the server-side session', function (string $provider) {
    startSocialFromModal($provider, 'register', '/posts/5?from=feed');

    expect(session('auth.surface_context'))->toBe([
        '_auth_surface' => 'modal',
        '_auth_mode' => 'register',
        '_auth_return_to' => '/posts/5?from=feed',
    ]);
})->with(['google', 'facebook']);

it('vets the return path before it ever reaches the session', function (string $returnTo) {
    Socialite::fake('google');

    $this->get('/auth/google?'.http_build_query(authModalFields('login', $returnTo)))->assertRedirect();

    expect(session('auth.surface_context._auth_return_to'))->toBe('/');
})->with(['https://evil.example', '//evil.example', '\\\\evil.example', 'javascript:alert(1)', 'data:text/html,x']);

it('forgets an abandoned modal attempt when the flow starts from a standalone page', function () {
    Socialite::fake('google');

    $this->withSession(['auth.surface_context' => authModalFields('login', '/posts/5')])
        ->get('/auth/google')
        ->assertRedirect();

    expect(session()->has('auth.surface_context'))->toBeFalse();
});

it('returns to the page of the modal after a successful provider sign-in', function (string $provider) {
    startSocialFromModal($provider, 'login', '/posts/5?from=feed');
    Socialite::fake($provider, fakeSocialiteUser(['id' => 'subject-42']));

    $this->get(socialCallbackUrl($provider))->assertRedirect('/posts/5?from=feed');

    $this->assertAuthenticated();
    expect(User::count())->toBe(1)
        ->and(SocialAccount::query()->sole()->provider)->toBe(SocialProvider::from($provider))
        ->and(session()->has('auth.surface_context'))->toBeFalse();
})->with(['google', 'facebook']);

it('returns a known identity to the page of the modal as well', function () {
    $user = User::factory()->create();
    SocialAccount::factory()->for($user)->google()->create(['provider_user_id' => 'g-1']);
    startSocialFromModal('google', 'login', '/?sort=top&page=2');
    Socialite::fake('google', fakeSocialiteUser(['id' => 'g-1', 'email' => $user->email]));

    $this->get(socialCallbackUrl('google'))->assertRedirect('/?sort=top&page=2');

    $this->assertAuthenticatedAs($user);
});

it('brings a cancelled provider sign-in back to its page with the modal open and the error shown', function (string $provider, string $mode) {
    $post = Post::factory()->published()->create();
    $page = route('posts.show', $post, absolute: false);
    $message = trans('auth.social.cancelled', ['provider' => SocialProvider::from($provider)->label()]);
    startSocialFromModal($provider, $mode, $page);

    $this->get(socialCallbackUrl($provider, ['code' => null, 'error' => 'access_denied']))->assertRedirect($page);
    $this->assertGuest();

    $modal = authModalElement($this->get($page)->assertOk()->getContent());
    $xpath = new DOMXPath($modal->ownerDocument);
    $other = $mode === 'login' ? 'register' : 'login';

    expect($modal->getAttribute('data-auth-modal-open'))->toBe('true')
        ->and($modal->getAttribute('data-auth-modal-mode'))->toBe($mode)
        ->and($xpath->evaluate('string(.//*[@data-testid="auth-modal-'.$mode.'-panel"]//*[@data-testid="social-error"])', $modal))->toContain($message)
        ->and($xpath->evaluate('string(.//*[@data-testid="auth-modal-'.$other.'-panel"])', $modal))->not->toContain($message);
})->with(['google', 'facebook'])->with(['login', 'register']);

it('keeps a provider failure that started in the modal out of the default error bag', function () {
    startSocialFromModal('google', 'register', '/posts/5');

    $response = $this->get(socialCallbackUrl('google', ['code' => null, 'error' => 'access_denied']));

    $response->assertRedirect('/posts/5');
    $response->assertSessionHasErrorsIn('authModal', ['social' => trans('auth.social.cancelled', ['provider' => 'Google'])]);
    $response->assertSessionDoesntHaveErrors(['social']);
    $response->assertSessionHas('auth_modal', ['mode' => 'register']);
});

it('brings every other expected provider failure back to the modal too', function (array $query, ?string $email, string $key) {
    startSocialFromModal('google', 'login', '/posts/5');
    Socialite::fake('google', fakeSocialiteUser(['email' => $email]));

    $response = $this->get(socialCallbackUrl('google', $query));

    $response->assertRedirect('/posts/5');
    $response->assertSessionHasErrorsIn('authModal', ['social' => trans($key, ['provider' => 'Google'])]);
    $response->assertSessionHas('auth_modal', ['mode' => 'login']);
    $this->assertGuest();
})->with([
    'provider error' => [['code' => null, 'error' => 'server_error'], 'ivan@example.com', 'auth.social.failed'],
    'no authorization code' => [['code' => null], 'ivan@example.com', 'auth.social.failed'],
    'no email shared' => [[], null, 'auth.social.email_missing'],
]);

it('keeps the standalone page flow for a provider sign-in that did not start in the modal', function () {
    Socialite::fake('google');
    $this->get('/auth/google')->assertRedirect();

    $response = $this->get(socialCallbackUrl('google', ['code' => null, 'error' => 'access_denied']));

    $response->assertRedirect(route('login'));
    $response->assertSessionHasErrors(['social' => trans('auth.social.cancelled', ['provider' => 'Google'])]);
    $response->assertSessionMissing('auth_modal');
});

it('reopens the modal in login mode with the pending-link message when the email already has an account', function (string $provider, string $startedIn) {
    $post = Post::factory()->published()->create();
    $page = route('posts.show', $post, absolute: false);
    User::factory()->create(['email' => 'ivan@example.com']);
    $message = trans('auth.social.pending_link', ['provider' => SocialProvider::from($provider)->label()]);
    startSocialFromModal($provider, $startedIn, $page);
    Socialite::fake($provider, fakeSocialiteUser(['id' => 'subject-42', 'email' => 'ivan@example.com']));

    $response = $this->get(socialCallbackUrl($provider));

    $response->assertRedirect($page);
    $this->assertGuest();
    expect(User::query()->where('email', 'ivan@example.com')->count())->toBe(1)
        ->and(SocialAccount::count())->toBe(0)
        ->and(session('auth.pending_social_link.provider_user_id'))->toBe('subject-42');

    $modal = authModalElement($this->get($page)->assertOk()->getContent());
    $xpath = new DOMXPath($modal->ownerDocument);

    // Whatever mode the person started in, they now have to sign in.
    expect($modal->getAttribute('data-auth-modal-open'))->toBe('true')
        ->and($modal->getAttribute('data-auth-modal-mode'))->toBe('login')
        ->and($xpath->evaluate('string(.//*[@data-testid="auth-modal-login-panel"]//*[@data-testid="auth-notice"])', $modal))->toContain($message)
        ->and($xpath->evaluate('string(.//*[@data-testid="auth-modal-register-panel"])', $modal))->not->toContain($message);
})->with(['google', 'facebook'])->with(['login', 'register']);

it('links the pending identity after a password login in the modal and lands on the original page', function (string $provider) {
    $user = User::factory()->create(['email' => 'ivan@example.com']);
    startSocialFromModal($provider, 'login', '/posts/5');
    Socialite::fake($provider, fakeSocialiteUser(['id' => 'subject-42', 'email' => 'ivan@example.com']));
    $this->get(socialCallbackUrl($provider))->assertRedirect('/posts/5');

    $this->post('/login', ['email' => 'ivan@example.com', 'password' => 'password'] + authModalFields('login', '/posts/5'))
        ->assertRedirect('/posts/5');

    $this->assertAuthenticatedAs($user);
    $account = SocialAccount::query()->sole();
    expect($account->user_id)->toBe($user->id)
        ->and($account->provider)->toBe(SocialProvider::from($provider))
        ->and($account->provider_user_id)->toBe('subject-42')
        ->and(session()->has('auth.pending_social_link'))->toBeFalse();
})->with(['google', 'facebook']);

it('lets a Google-only account confirm a pending Facebook link through Google, all from the modal', function () {
    $user = User::factory()->withoutPassword()->create(['email' => 'ivan@example.com']);
    SocialAccount::factory()->for($user)->google()->create(['provider_user_id' => 'g-1']);

    startSocialFromModal('facebook', 'login', '/posts/5');
    Socialite::fake('facebook', fakeSocialiteUser(['id' => 'fb-1', 'email' => 'ivan@example.com']));
    $this->get(socialCallbackUrl('facebook'))->assertRedirect('/posts/5');
    $this->assertGuest();

    startSocialFromModal('google', 'login', '/posts/5');
    Socialite::fake('google', fakeSocialiteUser(['id' => 'g-1', 'email' => 'ivan@example.com']));
    $this->get(socialCallbackUrl('google'))->assertRedirect('/posts/5');

    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->password)->toBeNull()
        ->and(SocialAccount::query()->where('user_id', $user->id)->where('provider', 'google')->sole()->provider_user_id)->toBe('g-1')
        ->and(SocialAccount::query()->where('user_id', $user->id)->where('provider', 'facebook')->sole()->provider_user_id)->toBe('fb-1')
        ->and(User::count())->toBe(1);
});

it('regenerates the session on a provider sign-in that started in the modal', function () {
    startSocialFromModal('google', 'login', '/posts/5');
    $before = session()->getId();
    Socialite::fake('google', fakeSocialiteUser());

    $this->get(socialCallbackUrl('google'))->assertRedirect('/posts/5');

    $this->assertAuthenticated();
    expect(session()->getId())->not->toBe($before);
});
