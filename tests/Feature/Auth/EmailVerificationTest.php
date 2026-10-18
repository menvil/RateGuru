<?php

use App\Actions\Auth\SendEmailVerificationNotificationAction;
use App\Actions\Auth\VerifyEmailAction;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

test('email verification screen can be rendered', function () {
    $user = User::factory()->unverified()->create();

    $response = $this->actingAs($user)->get('/verify-email');

    $response->assertStatus(200);
});

test('email can be verified', function () {
    $user = User::factory()->unverified()->create();

    Event::fake();

    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1($user->email)]
    );

    $response = $this->actingAs($user)->get($verificationUrl);

    Event::assertDispatched(Verified::class);
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
    $response->assertRedirect(route('dashboard', absolute: false).'?verified=1');
});

test('email is not verified with invalid hash', function () {
    $user = User::factory()->unverified()->create();

    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1('wrong-email')]
    );

    $this->actingAs($user)->get($verificationUrl);

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

/*
 * A Persona's synthetic address is never confirmed: no link is sent for it,
 * however it is asked for, and none confirms it.
 */
test('a persona is never sent a verification link', function () {
    Notification::fake();

    $persona = User::factory()->persona()->create();

    expect(app(SendEmailVerificationNotificationAction::class)->execute($persona))->toBeFalse();

    $persona->sendEmailVerificationNotification();
    event(new Registered($persona));

    Notification::assertNothingSentTo($persona);
});

test('a member who has not confirmed their address is still sent a link', function () {
    Notification::fake();

    $user = User::factory()->unverified()->create();

    expect(app(SendEmailVerificationNotificationAction::class)->execute($user))->toBeTrue();

    Notification::assertSentTo($user, VerifyEmail::class);
});

test('a persona address is never confirmed', function () {
    Event::fake([Verified::class]);

    $persona = User::factory()->persona()->create();

    expect(app(VerifyEmailAction::class)->execute($persona))->toBeFalse()
        ->and($persona->fresh()->hasVerifiedEmail())->toBeFalse();

    Event::assertNotDispatched(Verified::class);
});
