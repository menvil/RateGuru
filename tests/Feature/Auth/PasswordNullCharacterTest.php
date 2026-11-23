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

it('answers a sign-in with one as a wrong password', function () {
    $user = User::factory()->create();

    $this->post('/login', ['email' => $user->email, 'password' => $this->password])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});
