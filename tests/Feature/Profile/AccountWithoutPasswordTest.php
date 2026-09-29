<?php

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

/*
 * An account created through Google or Facebook has no password. Its owner
 * can still secure it (get a password through the emailed link) and delete
 * it (confirming with the account's email) — and an account with a password
 * keeps working exactly as before.
 */

it('offers a social-only account a password link instead of a change-password form', function () {
    $user = User::factory()->withoutPassword()->create();

    $this->actingAs($user)->get('/profile')
        ->assertOk()
        ->assertSee('data-testid="set-password-section"', false)
        ->assertSee(__('profile.password.set_title'))
        ->assertDontSee('name="current_password"', false);
});

it('keeps the change-password form for an account that has a password', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/profile')
        ->assertOk()
        ->assertSee('name="current_password"', false)
        ->assertDontSee('data-testid="set-password-section"', false);
});

it('emails the password link to the account itself', function () {
    Notification::fake();
    $user = User::factory()->withoutPassword()->create();

    $this->actingAs($user)->from('/profile')
        ->post(route('password.set-link'))
        ->assertRedirect('/profile')
        ->assertSessionHas('status', 'password-set-link-sent')
        ->assertSessionHasNoErrors();

    Notification::assertSentTo($user, ResetPassword::class);
});

it('reports a repeated password link request in its own section', function () {
    Notification::fake();
    $user = User::factory()->withoutPassword()->create();

    $this->actingAs($user)->from('/profile')->post(route('password.set-link'));

    $this->actingAs($user)->from('/profile')->post(route('password.set-link'))
        ->assertRedirect('/profile')
        ->assertSessionHasErrorsIn('passwordSetLink', ['email'])
        ->assertSessionDoesntHaveErrors(['email']);

    Notification::assertSentToTimes($user, ResetPassword::class, 1);
});

it('does not send a password link to a guest', function () {
    $this->post(route('password.set-link'))->assertRedirect(route('login'));
});

it('sets the first password from the emailed link while signed in', function () {
    Notification::fake();
    $user = User::factory()->withoutPassword()->create();

    $this->actingAs($user)->post(route('password.set-link'));

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user): bool {
        $this->actingAs($user)
            ->get(route('password.reset', ['token' => $notification->token, 'email' => $user->email]))
            ->assertOk();

        $this->actingAs($user)->post(route('password.store'), [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'chosen-password',
            'password_confirmation' => 'chosen-password',
        ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHas('status', 'password-set');

        return true;
    });

    $this->assertAuthenticatedAs($user);
    expect(Hash::check('chosen-password', (string) $user->fresh()->password))->toBeTrue();

    // From now on it is an ordinary account with a password.
    $this->actingAs($user->fresh())->get('/profile')
        ->assertSee('name="current_password"', false)
        ->assertDontSee('data-testid="set-password-section"', false);
});

it('still sends a signed-out reset back to the login page', function () {
    Notification::fake();
    $user = User::factory()->create();

    $this->post('/forgot-password', ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user): bool {
        $this->post(route('password.store'), [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertRedirect(route('login'));

        return true;
    });

    $this->assertGuest();
});

it('lets a social-only account delete itself by confirming its email', function (string $typed) {
    $user = User::factory()->withoutPassword()->create(['email' => 'ivan@example.com']);

    $this->actingAs($user)->from('/profile')
        ->delete('/profile', ['email' => $typed])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/');

    $this->assertGuest();
    expect($user->fresh()->status)->toBe(UserStatus::Deleted);
})->with(['ivan@example.com', '  Ivan@Example.com ']);

it('refuses to delete a social-only account confirmed with another email', function () {
    $user = User::factory()->withoutPassword()->create(['email' => 'ivan@example.com']);

    $this->actingAs($user)->from('/profile')
        ->delete('/profile', ['email' => 'someone-else@example.com'])
        ->assertRedirect('/profile')
        ->assertSessionHasErrorsIn('userDeletion', ['email' => __('profile.delete.email_mismatch')]);

    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->status)->not->toBe(UserStatus::Deleted);
});

it('still requires the password to delete an account that has one', function () {
    $user = User::factory()->create(['email' => 'ivan@example.com']);

    $this->actingAs($user)->from('/profile')
        ->delete('/profile', ['email' => 'ivan@example.com'])
        ->assertRedirect('/profile')
        ->assertSessionHasErrorsIn('userDeletion', ['password']);

    expect($user->fresh()->status)->not->toBe(UserStatus::Deleted);
});

it('asks each account for the confirmation it can give', function () {
    $social = User::factory()->withoutPassword()->create();
    $withPassword = User::factory()->create();

    $this->actingAs($social)->get('/profile')
        ->assertSee('data-testid="delete-account-email"', false)
        ->assertDontSee('data-testid="delete-account-password"', false);

    $this->actingAs($withPassword)->get('/profile')
        ->assertSee('data-testid="delete-account-password"', false)
        ->assertDontSee('data-testid="delete-account-email"', false);
});

it('reopens the deletion dialog after a failed confirmation, and only then', function () {
    $user = User::factory()->create();

    $closed = $this->actingAs($user)->get('/profile')->getContent();
    expect($closed)->toContain('x-data="{ deleteOpen: false }"');

    $this->actingAs($user)->from('/profile')->delete('/profile', ['password' => 'wrong-password']);

    $reopened = $this->actingAs($user)->get('/profile')->getContent();
    expect($reopened)->toContain('x-data="{ deleteOpen: true }"');
});

it('renders the deletion dialog with the shared modal and its dialog behaviours', function () {
    $html = $this->actingAs(User::factory()->create())->get('/profile')->getContent();

    expect($html)
        ->toContain('data-testid="delete-account-modal"')
        ->toContain('x-trap.noscroll="deleteOpen"')
        ->toContain('x-on:keydown.escape.window="deleteOpen = false"')
        ->toContain('data-modal-below-header')
        ->not->toContain('open-modal');
});
