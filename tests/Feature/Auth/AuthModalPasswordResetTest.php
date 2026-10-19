<?php

use App\Models\Post;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;

/*
 * A reset link requested from the authentication modal: the same
 * POST /forgot-password, SendPasswordResetLinkRequest and
 * SendPasswordResetLinkAction as the standalone page, plus the way back to
 * the page the modal was opened on — with the modal open again, in the same
 * state, saying what happened.
 */

it('emails the link and comes back to the page with the modal saying so', function () {
    Notification::fake();
    $post = Post::factory()->published()->create();
    $user = User::factory()->create(['email' => 'ivan@example.com']);
    $page = route('posts.show', $post, absolute: false).'?from=feed';

    $this->post('/forgot-password', ['email' => 'ivan@example.com'] + authModalFields('forgot-password', $page))
        ->assertRedirect($page)
        ->assertSessionHasNoErrors();

    Notification::assertSentTo($user, ResetPassword::class);

    $modal = authModalElement($this->get($page)->assertOk()->getContent());
    $xpath = new DOMXPath($modal->ownerDocument);

    expect($modal->getAttribute('data-auth-modal-open'))->toBe('true')
        ->and($modal->getAttribute('data-auth-modal-mode'))->toBe('forgot-password')
        ->and($xpath->evaluate('string(.//*[@data-testid="auth-modal-forgot-password-panel"]//*[@data-testid="auth-notice"])', $modal))
        ->toContain(trans('passwords.sent'))
        // The notice belongs to the reset-link state only.
        ->and($xpath->query('.//*[@data-testid="auth-modal-login-panel"]//*[@data-testid="auth-notice"]', $modal)->length)->toBe(0);
});

it('reopens the modal in the reset-link state when the address has no account', function () {
    Notification::fake();
    $page = '/?sort=top';

    $this->post('/forgot-password', ['email' => 'nobody@example.com'] + authModalFields('forgot-password', $page))
        ->assertRedirect($page);

    Notification::assertNothingSent();

    // Followed straight away, the way a browser does: reading the session's
    // errors in between would consume them before the page can show them.
    $modal = authModalElement($this->get($page)->assertOk()->getContent());
    $xpath = new DOMXPath($modal->ownerDocument);

    expect($modal->getAttribute('data-auth-modal-open'))->toBe('true')
        ->and($modal->getAttribute('data-auth-modal-mode'))->toBe('forgot-password')
        ->and($xpath->evaluate('string(.//*[@data-testid="auth-modal-forgot-password-panel"])', $modal))->toContain(trans('passwords.user'))
        ->and($xpath->evaluate('string(.//input[@id="modal-forgot-password-email"]/@value)', $modal))->toBe('nobody@example.com')
        // The login form of the same dialog shares the field name, not the error.
        ->and($xpath->evaluate('string(.//*[@data-testid="auth-modal-login-panel"])', $modal))->not->toContain(trans('passwords.user'))
        ->and($xpath->evaluate('string(.//input[@id="modal-login-email"]/@value)', $modal))->toBe('');
});

it('keeps the errors of a failed modal request in the bag of the modal', function (string $email, string $messageKey) {
    $response = $this->post('/forgot-password', ['email' => $email] + authModalFields('forgot-password', '/posts/123'));

    $response->assertRedirect('/posts/123');
    $response->assertSessionHasErrorsIn('authModal', ['email' => trans($messageKey, ['attribute' => 'email'])]);
    $response->assertSessionDoesntHaveErrors(['email']);
    $response->assertSessionHasInput('email', $email);
})->with([
    'no account for the address' => ['nobody@example.com', 'passwords.user'],
    'not an address at all' => ['not-an-email', 'validation.email'],
]);

it('reports a second request inside the throttle window in the modal too', function () {
    Notification::fake();
    User::factory()->create(['email' => 'ivan@example.com']);
    $request = fn () => $this->post('/forgot-password', ['email' => 'ivan@example.com'] + authModalFields('forgot-password', '/posts/123'));

    $request()->assertSessionHasNoErrors();

    $request()
        ->assertRedirect('/posts/123')
        ->assertSessionHasErrorsIn('authModal', ['email' => trans('passwords.throttled')]);
});

it('never follows a hostile return path after requesting a link', function (mixed $returnTo) {
    Notification::fake();
    User::factory()->create(['email' => 'ivan@example.com']);

    $this->post('/forgot-password', [
        'email' => 'ivan@example.com',
        '_auth_surface' => 'modal',
        '_auth_mode' => 'forgot-password',
        '_auth_return_to' => $returnTo,
    ])->assertRedirect('/');
})->with('hostile return paths');

it('leaves the standalone page answering on the page itself', function () {
    Notification::fake();
    User::factory()->create(['email' => 'ivan@example.com']);

    $this->from('/forgot-password')
        ->post('/forgot-password', ['email' => 'ivan@example.com'])
        ->assertRedirect('/forgot-password')
        ->assertSessionHas('status', trans('passwords.sent'))
        ->assertSessionMissing('auth_modal');

    $this->from('/forgot-password')
        ->post('/forgot-password', ['email' => 'nobody@example.com'])
        ->assertRedirect('/forgot-password')
        ->assertSessionHasErrors(['email' => trans('passwords.user')]);
});
