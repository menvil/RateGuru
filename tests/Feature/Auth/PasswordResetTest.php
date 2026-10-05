<?php

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

test('reset password link screen can be rendered', function () {
    $response = $this->get('/forgot-password');

    $response->assertStatus(200);
});

test('reset password link can be requested', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post('/forgot-password', ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class);
});

test('reset password screen can be rendered', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post('/forgot-password', ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function ($notification) {
        $response = $this->get('/reset-password/'.$notification->token);

        $response->assertStatus(200);

        return true;
    });
});

test('password can be reset with valid token', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post('/forgot-password', ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
        $response = $this->post('/reset-password', [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('login'));

        return true;
    });
});

test('the PasswordReset event carries the credentials the reset actually wrote', function () {
    Notification::fake();

    $user = User::factory()->create(['password' => Hash::make('the-old-password')]);
    $originalRememberToken = $user->remember_token;

    $token = Password::broker()->createToken($user);

    $captured = null;
    Event::listen(function (PasswordReset $event) use (&$captured): void {
        $captured = $event->user;
    });

    $this->post('/reset-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'a-brand-new-password',
        'password_confirmation' => 'a-brand-new-password',
    ])->assertSessionHasNoErrors();

    expect($captured)->not->toBeNull();

    // The row that was written, not the instance the broker handed over: a
    // listener reading the password or remember_token off this event must not be
    // reading the credentials the reset just replaced.
    expect(Hash::check('a-brand-new-password', (string) $captured->password))->toBeTrue()
        ->and(Hash::check('the-old-password', (string) $captured->password))->toBeFalse()
        ->and($captured->remember_token)->not->toBe($originalRememberToken)
        ->and($captured->getKey())->toBe($user->getKey());
});
