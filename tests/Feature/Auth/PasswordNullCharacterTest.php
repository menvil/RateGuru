<?php

use App\Livewire\Admin\UserEditor;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;

/*
 * Bcrypt answers a null character with an error instead of a hash, so a
 * password holding one is a mistake in the form wherever a password is set.
 */

beforeEach(function () {
    $this->password = "long-enough\0password";
});

it('refuses it at sign-up', function () {
    $this->post('/register', [
        'name' => 'Ada',
        'email' => 'ada@example.test',
        'password' => $this->password,
        'password_confirmation' => $this->password,
    ])->assertSessionHasErrors('password');

    $this->assertGuest();
    expect(User::query()->where('email', 'ada@example.test')->exists())->toBeFalse();
});

it('refuses it on a password reset', function () {
    $user = User::factory()->create();

    $this->post('/reset-password', [
        'token' => Password::createToken($user),
        'email' => $user->email,
        'password' => $this->password,
        'password_confirmation' => $this->password,
    ])->assertSessionHasErrors('password');

    expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
});

it('refuses it on the profile', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->from('/profile')->put('/password', [
        'current_password' => 'password',
        'password' => $this->password,
        'password_confirmation' => $this->password,
    ])->assertSessionHasErrorsIn('updatePassword', 'password');

    expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
});

it('refuses it in the account editor', function () {
    $person = User::factory()->create();
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(UserEditor::class, ['userId' => $person->id])
        ->set('password', $this->password)
        ->call('save')
        ->assertHasErrors('password');

    expect(Hash::check('password', $person->fresh()->password))->toBeTrue();
});

// Bcrypt reads only as far as the character, so each of these would pass as
// the account's real password, "password", without the rule.
it('does not sign in with the password and one after it', function () {
    $user = User::factory()->create();

    $this->post('/login', ['email' => $user->email, 'password' => "password\0suffix"])
        ->assertSessionHasErrors('password');

    $this->assertGuest();
});

it('does not confirm the password with one after it', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/confirm-password', ['password' => "password\0suffix"])
        ->assertSessionHasErrors('password')
        ->assertSessionMissing('auth.password_confirmed_at');
});

it('does not take it as the current password for a new one', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->from('/profile')->put('/password', [
        'current_password' => "password\0suffix",
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ])->assertSessionHasErrorsIn('updatePassword', 'current_password');

    expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
});

it('does not take it as the password that deletes the account', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->from('/profile')->delete('/profile', ['password' => "password\0suffix"])
        ->assertSessionHasErrorsIn('userDeletion', 'password');

    expect($user->fresh()->isTombstoned())->toBeFalse();
});

it('does not take it as the password that moves the account to a new address', function () {
    $user = User::factory()->create(['email' => 'kept@example.com']);

    $this->actingAs($user)->from('/profile')->patch('/profile', [
        'name' => $user->name,
        'username' => $user->username,
        'email' => 'moved@example.com',
        'current_password' => "password\0suffix",
    ])->assertSessionHasErrors('current_password');

    expect($user->fresh()->email)->toBe('kept@example.com');
});
