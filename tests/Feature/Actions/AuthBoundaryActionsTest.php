<?php

use App\Actions\Auth\AuthenticateUserAction;
use App\Actions\Auth\ConfirmPasswordAction;
use App\Actions\Auth\LogoutUserAction;
use App\Actions\Auth\RegisterUserAction;
use App\Actions\Auth\ResetPasswordAction;
use App\Actions\Auth\SendEmailVerificationNotificationAction;
use App\Actions\Auth\SendPasswordResetLinkAction;
use App\Actions\Auth\UpdatePasswordAction;
use App\Actions\Auth\VerifyEmailAction;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

it('registers and authenticates a user through the registration action', function () {
    Event::fake([Registered::class]);

    $user = app(RegisterUserAction::class)->execute([
        'name' => 'Action User',
        'email' => 'action-user@example.com',
        'password' => 'password',
    ]);

    expect($user->username)->toBe('action_user')
        ->and(Hash::check('password', $user->password))->toBeTrue();
    $this->assertAuthenticatedAs($user);
    Event::assertDispatched(Registered::class, fn (Registered $event): bool => $event->user->is($user));
});

it('authenticates validated credentials and rejects an invalid password', function () {
    $user = User::factory()->create();
    $request = Request::create('/login', 'POST', server: ['REMOTE_ADDR' => '127.0.0.1']);

    app(AuthenticateUserAction::class)->execute([
        'email' => $user->email,
        'password' => 'password',
        'remember' => true,
    ], $request);

    $this->assertAuthenticatedAs($user);

    Auth::logout();

    expect(fn () => app(AuthenticateUserAction::class)->execute([
        'email' => $user->email,
        'password' => 'wrong-password',
        'remember' => false,
    ], $request))->toThrow(ValidationException::class);
});

it('confirms the current user password through the confirmation action', function () {
    $user = User::factory()->create();

    app(ConfirmPasswordAction::class)->execute($user, 'password');

    expect(fn () => app(ConfirmPasswordAction::class)->execute($user, 'wrong-password'))
        ->toThrow(ValidationException::class);
});

it('sends password reset links through the reset link action', function () {
    Notification::fake();
    $user = User::factory()->create();

    $status = app(SendPasswordResetLinkAction::class)->execute($user->email);

    expect($status)->toBe(Password::RESET_LINK_SENT);
    Notification::assertSentTo($user, ResetPassword::class);
});

it('resets passwords and dispatches the framework event through the reset action', function () {
    Event::fake([PasswordReset::class]);
    $user = User::factory()->create();
    $token = Password::broker()->createToken($user);

    $status = app(ResetPasswordAction::class)->execute([
        'token' => $token,
        'email' => $user->email,
        'password' => 'new-password',
    ]);

    expect($status)->toBe(Password::PASSWORD_RESET)
        ->and(Hash::check('new-password', $user->refresh()->password))->toBeTrue();
    Event::assertDispatched(PasswordReset::class);
});

it('updates passwords and logs users out through auth actions', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    app(UpdatePasswordAction::class)->execute($user, 'new-password');

    expect(Hash::check('new-password', $user->refresh()->password))->toBeTrue();

    app(LogoutUserAction::class)->execute();

    $this->assertGuest();
});

it('sends and completes email verification through auth actions', function () {
    Notification::fake();
    Event::fake([Verified::class]);
    $user = User::factory()->unverified()->create();

    expect(app(SendEmailVerificationNotificationAction::class)->execute($user))->toBeTrue();
    Notification::assertSentTo($user, VerifyEmail::class);

    app(VerifyEmailAction::class)->execute($user);

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
    Event::assertDispatched(Verified::class);
});
