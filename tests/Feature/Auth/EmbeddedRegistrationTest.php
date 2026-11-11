<?php

use App\Models\Post;
use App\Models\User;

/*
 * Registration posted from a form set into a page — the sign-up on a game's
 * result — rather than from the modal: the same POST /register, back to
 * that page, but with the modal left shut and the errors kept for the form.
 */

function embeddedRegistration(array $overrides = [], string $page = '/games/trust-your-taste'): array
{
    return array_merge([
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        '_auth_surface' => 'embedded',
        '_auth_mode' => 'register',
        '_auth_return_to' => $page,
    ], $overrides);
}

it('returns to the page the form is on after registering', function () {
    $this->post('/register', embeddedRegistration())->assertRedirect('/games/trust-your-taste');

    $this->assertAuthenticated();
});

it('sends a failure back to the page with the errors in the authentication bag', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    $this->post('/register', embeddedRegistration(['email' => 'taken@example.com']))
        ->assertRedirect('/games/trust-your-taste')
        ->assertSessionHasErrorsIn('authModal', ['email']);

    $this->assertGuest();
});

it('leaves the modal shut after a failure: the form on the page shows it', function () {
    $post = Post::factory()->published()->create();
    User::factory()->create(['email' => 'taken@example.com']);
    $page = route('posts.show', $post, absolute: false);

    $this->post('/register', embeddedRegistration(['email' => 'taken@example.com'], $page))->assertRedirect($page);

    $modal = authModalElement($this->get($page)->assertOk()->getContent());

    expect($modal->getAttribute('data-auth-modal-open'))->toBe('false')
        ->and($modal->textContent)->not->toContain(trans('validation.unique', ['attribute' => 'email']));
});

it('never follows a return path off the application', function (string $unsafe) {
    $this->post('/register', embeddedRegistration(page: $unsafe))->assertRedirect('/');
})->with(['https://evil.example/', '//evil.example/']);
