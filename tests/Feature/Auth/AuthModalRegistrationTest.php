<?php

use App\Actions\Users\GenerateUniqueUsernameAction;
use App\Models\Post;
use App\Models\User;

/*
 * Registration submitted from the authentication modal: the same
 * POST /register, RegisterUserRequest and RegisterUserAction as the
 * standalone page, plus the way back to the page the modal was opened on.
 */

function modalRegistration(array $overrides = [], string $page = '/posts/123'): array
{
    return array_merge([
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ], $overrides) + authModalFields('register', $page);
}

it('returns to the page the modal was opened on after registering', function (string $page) {
    $response = $this->post('/register', modalRegistration(page: $page));

    $response->assertRedirect($page);
    $this->assertAuthenticated();
    $this->assertDatabaseHas('users', ['email' => 'test@example.com', 'username' => 'test_user']);
})->with(['/posts/123', '/?sort=top&page=2']);

it('sends a failed modal registration back to its page and reopens the modal in register mode', function () {
    $post = Post::factory()->published()->create();
    User::factory()->create(['email' => 'taken@example.com']);
    $page = route('posts.show', $post, absolute: false);

    $response = $this->post('/register', modalRegistration(['name' => 'Ivan Moroz', 'email' => 'taken@example.com'], $page));

    $response->assertRedirect($page);
    $this->assertGuest();

    // Followed straight away, the way a browser does: reading the session's
    // errors in between would consume them before the page can show them.
    $html = $this->get($page)->assertOk()->getContent();
    $modal = authModalElement($html);
    $xpath = new DOMXPath($modal->ownerDocument);
    $message = trans('validation.unique', ['attribute' => 'email']);

    expect($modal->getAttribute('data-auth-modal-open'))->toBe('true')
        ->and($modal->getAttribute('data-auth-modal-mode'))->toBe('register')
        ->and($xpath->evaluate('string(.//*[@data-testid="auth-modal-register-panel"])', $modal))->toContain($message)
        // Non-sensitive input comes back, passwords never do.
        ->and($xpath->evaluate('string(.//input[@id="modal-register-name"]/@value)', $modal))->toBe('Ivan Moroz')
        ->and($xpath->evaluate('string(.//input[@id="modal-register-email"]/@value)', $modal))->toBe('taken@example.com')
        ->and($xpath->evaluate('string(.//input[@id="modal-register-password"]/@value)', $modal))->toBe('')
        ->and($xpath->evaluate('string(.//input[@id="modal-register-password-confirmation"]/@value)', $modal))->toBe('')
        // It is the registration form that failed, not the login form.
        ->and($xpath->evaluate('string(.//*[@data-testid="auth-modal-login-panel"])', $modal))->not->toContain($message)
        ->and($xpath->evaluate('string(.//input[@id="modal-login-email"]/@value)', $modal))->toBe('');
});

it('keeps the errors of a failed modal registration in the bag of the modal', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    $response = $this->post('/register', modalRegistration(['email' => 'taken@example.com', 'password_confirmation' => 'different']));

    $response->assertRedirect('/posts/123');
    $response->assertSessionHasErrorsIn('authModal', ['email', 'password']);
    $response->assertSessionDoesntHaveErrors(['email', 'password']);
    $response->assertSessionHasInput('name', 'Test User');
    $response->assertSessionMissing('_old_input.password');
    $response->assertSessionMissing('_old_input.password_confirmation');
    $this->assertGuest();
});

it('reports a username that could not be generated in the modal as well', function () {
    $action = Mockery::mock(GenerateUniqueUsernameAction::class);
    $action->shouldReceive('handle')->once()->andThrow(new RuntimeException('Unable to generate a unique username.'));
    app()->instance(GenerateUniqueUsernameAction::class, $action);

    $response = $this->post('/register', modalRegistration());

    $response->assertRedirect('/posts/123');
    $response->assertSessionHasErrorsIn('authModal', ['name' => __('auth.username_unavailable')]);
});

it('never follows a hostile return path after registering', function (mixed $returnTo) {
    $response = $this->post('/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        '_auth_surface' => 'modal',
        '_auth_mode' => 'register',
        '_auth_return_to' => $returnTo,
    ]);

    $response->assertRedirect('/');
    $this->assertAuthenticated();
})->with([
    'absolute https' => ['https://evil.example'],
    'protocol relative' => ['//evil.example'],
    'backslash pair' => ['\\\\evil.example'],
    'javascript scheme' => ['javascript:alert(1)'],
    'data scheme' => ['data:text/html,<script>alert(1)</script>'],
]);

it('keeps the standalone registration exactly as it was', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    $this->from('/register')
        ->post('/register', [
            'name' => 'Test User',
            'email' => 'taken@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])
        ->assertRedirect('/register')
        ->assertSessionHasErrors(['email']);

    // An intended url was never honoured by standalone registration, and still is not.
    $this->withSession(['url.intended' => '/saved'])
        ->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticated();
});
