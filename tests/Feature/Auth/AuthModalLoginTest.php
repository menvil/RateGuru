<?php

use App\Models\Post;
use App\Models\User;

/*
 * Email/password login submitted from the authentication modal: the same
 * POST /login as the standalone page, plus the marker fields that bring the
 * person back to the page the modal was opened on.
 */

it('returns to the page the modal was opened on, query string included', function (string $page) {
    $user = User::factory()->create();

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ] + authModalFields('login', $page));

    $response->assertRedirect($page);
    $this->assertAuthenticatedAs($user);
})->with(['/posts/123', '/?sort=top&page=2', '/u/someone?tab=posts']);

it('prefers the page of the modal over a stale intended url', function () {
    $user = User::factory()->create();

    $this->withSession(['url.intended' => '/saved'])
        ->post('/login', ['email' => $user->email, 'password' => 'password'] + authModalFields('login', '/posts/123'))
        ->assertRedirect('/posts/123');
});

it('sends a failed modal login back to its page and reopens the modal in login mode', function () {
    $post = Post::factory()->published()->create();
    User::factory()->create(['email' => 'ivan@example.com']);
    $page = route('posts.show', $post, absolute: false).'?from=feed';

    $response = $this->post('/login', [
        'email' => 'ivan@example.com',
        'password' => 'wrong-password',
    ] + authModalFields('login', $page));

    $response->assertRedirect($page);
    $this->assertGuest();

    // Followed straight away, the way a browser does: reading the session's
    // errors in between would consume them before the page can show them.
    $html = $this->get($page)->assertOk()->getContent();
    $modal = authModalElement($html);
    $xpath = new DOMXPath($modal->ownerDocument);

    expect($modal->getAttribute('data-auth-modal-open'))->toBe('true')
        ->and($modal->getAttribute('data-auth-modal-mode'))->toBe('login')
        ->and($xpath->evaluate('string(.//*[@data-testid="auth-modal-login-panel"])', $modal))->toContain(trans('auth.failed'))
        // The entered email is kept, the password never is.
        ->and($xpath->evaluate('string(.//input[@id="modal-login-email"]/@value)', $modal))->toBe('ivan@example.com')
        ->and($xpath->evaluate('string(.//input[@id="modal-login-password"]/@value)', $modal))->toBe('')
        ->and($html)->not->toContain('wrong-password')
        // The other form of the same dialog is not the one that failed.
        ->and($xpath->evaluate('string(.//*[@data-testid="auth-modal-register-panel"])', $modal))->not->toContain(trans('auth.failed'))
        ->and($xpath->evaluate('string(.//input[@id="modal-register-email"]/@value)', $modal))->toBe('');
});

it('keeps the errors of a failed modal login in the bag of the modal', function () {
    User::factory()->create(['email' => 'ivan@example.com']);

    $response = $this->post('/login', [
        'email' => 'ivan@example.com',
        'password' => 'wrong-password',
    ] + authModalFields('login', '/posts/123'));

    $response->assertRedirect('/posts/123');
    $response->assertSessionHasErrorsIn('authModal', ['email' => trans('auth.failed')]);
    $response->assertSessionDoesntHaveErrors(['email']);
    $response->assertSessionHasInput('email', 'ivan@example.com');
    $response->assertSessionMissing('_old_input.password');
});

it('reopens the modal for a login that fails validation before any credential is checked', function () {
    $response = $this->post('/login', ['email' => 'not-an-email', 'password' => ''] + authModalFields('login', '/posts/123'));

    $response->assertRedirect('/posts/123');
    $response->assertSessionHasErrorsIn('authModal', ['email', 'password']);
    $response->assertSessionDoesntHaveErrors(['email', 'password']);
    $this->assertGuest();
});

it('reports a throttled modal login in the modal too', function () {
    User::factory()->create(['email' => 'ivan@example.com']);
    $attempt = fn () => $this->post('/login', [
        'email' => 'ivan@example.com',
        'password' => 'wrong-password',
    ] + authModalFields('login', '/posts/123'));

    foreach (range(1, 5) as $ignored) {
        $attempt();
    }

    $response = $attempt();

    $response->assertRedirect('/posts/123');
    $response->assertSessionHasErrorsIn('authModal', ['email']);
    $response->assertSessionDoesntHaveErrors(['email' => trans('auth.failed')], null, 'authModal');
    $response->assertSessionDoesntHaveErrors(['email']);
});

it('never follows a hostile return path after a successful login', function (mixed $returnTo) {
    $user = User::factory()->create();

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
        '_auth_surface' => 'modal',
        '_auth_mode' => 'login',
        '_auth_return_to' => $returnTo,
    ]);

    $response->assertRedirect('/');
    $this->assertAuthenticatedAs($user);
})->with('hostile return paths');

it('treats a return path that is not even a string as a failed submission, safely', function () {
    $user = User::factory()->create();

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
        '_auth_surface' => 'modal',
        '_auth_mode' => 'login',
        '_auth_return_to' => ['/posts/1'],
    ]);

    $response->assertRedirect('/');
    $this->assertGuest();
});

it('never follows a hostile return path after a failed login', function (mixed $returnTo) {
    $user = User::factory()->create();

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
        '_auth_surface' => 'modal',
        '_auth_mode' => 'login',
        '_auth_return_to' => $returnTo,
    ]);

    $response->assertRedirect('/');
    $this->assertGuest();
})->with('hostile return paths');

it('ignores a return path that does not come with the modal marker', function () {
    $user = User::factory()->create();

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
        '_auth_return_to' => '/posts/123',
    ])->assertRedirect(route('dashboard', absolute: false));
});

it('keeps the standalone login exactly as it was', function () {
    $user = User::factory()->create();

    $this->from('/login')
        ->post('/login', ['email' => $user->email, 'password' => 'wrong-password'])
        ->assertRedirect('/login')
        ->assertSessionHasErrors(['email' => trans('auth.failed')]);

    $this->withSession(['url.intended' => '/saved'])
        ->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect('/saved');

    $this->assertAuthenticatedAs($user);
});

dataset('hostile return paths', [
    'absolute https' => ['https://evil.example'],
    'absolute http' => ['http://evil.example/posts/1'],
    'protocol relative' => ['//evil.example'],
    'backslash pair' => ['\\\\evil.example'],
    'slash then backslash' => ['/\\evil.example'],
    'javascript scheme' => ['javascript:alert(1)'],
    'data scheme' => ['data:text/html,<script>alert(1)</script>'],
    'header injection' => ["/posts/1\r\nLocation: https://evil.example"],
    'relative path' => ['posts/123'],
]);
