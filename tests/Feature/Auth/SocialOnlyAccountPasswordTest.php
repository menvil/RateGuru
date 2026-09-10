<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

/*
 * An account created through a provider has no password at all. Every
 * existing password flow must treat that as "no credential" — failing with
 * its normal generic outcome, never with an exception — and password reset
 * must remain the one way to obtain a password later.
 */

it('stores no password hash at all for a social-only account', function () {
    $user = User::factory()->withoutPassword()->create();

    $this->assertDatabaseHas('users', ['id' => $user->id, 'password' => null]);
    expect($user->fresh()->password)->toBeNull();
});

it('refuses password login for a social-only account with the generic failure', function (string $password) {
    $user = User::factory()->withoutPassword()->create();

    $response = $this->post('/login', ['email' => $user->email, 'password' => $password]);

    $response->assertSessionHasErrors(['email' => trans('auth.failed')]);
    $this->assertGuest();
})->with(['password', 'wrong-password']);

it('fails password confirmation safely for a social-only account', function () {
    $user = User::factory()->withoutPassword()->create();

    $response = $this->actingAs($user)->from('/confirm-password')->post('/confirm-password', ['password' => 'anything']);

    $response->assertRedirect('/confirm-password');
    $response->assertSessionHasErrors('password');
    expect(session('auth.password_confirmed_at'))->toBeNull();
});

it('still renders the password confirmation screen for a social-only account', function () {
    $user = User::factory()->withoutPassword()->create();

    $this->actingAs($user)->get('/confirm-password')->assertOk();
});

it('fails a password update safely for a social-only account', function () {
    $user = User::factory()->withoutPassword()->create();

    $response = $this->actingAs($user)->from('/profile')->put('/password', [
        'current_password' => 'anything',
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ]);

    $response->assertRedirect('/profile');
    $response->assertSessionHasErrorsIn('updatePassword', 'current_password');
    expect($user->fresh()->password)->toBeNull();
});

it('lets a social-only account obtain a password through the reset flow', function () {
    Notification::fake();
    $user = User::factory()->withoutPassword()->create();

    $this->post('/forgot-password', ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user): bool {
        $this->post('/reset-password', [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'chosen-password',
            'password_confirmation' => 'chosen-password',
        ])->assertSessionHasNoErrors()->assertRedirect(route('login'));

        return true;
    });

    expect(Hash::check('chosen-password', (string) $user->fresh()->password))->toBeTrue();

    $this->post('/login', ['email' => $user->email, 'password' => 'chosen-password']);
    $this->assertAuthenticatedAs($user);
});

it('keeps the profile page working for a social-only account', function () {
    $user = User::factory()->withoutPassword()->create();

    $this->actingAs($user)->get('/profile')->assertOk();
});
