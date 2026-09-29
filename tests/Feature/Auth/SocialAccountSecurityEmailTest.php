<?php

use App\Data\Auth\PendingSocialLink;
use App\Enums\SocialProvider;
use App\Models\SocialAccount;
use App\Models\User;
use App\Notifications\SocialAccountConnectedNotification;
use App\Notifications\SocialAccountDisconnectedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;
use Laravel\Socialite\Facades\Socialite;

/*
 * Every Google or Facebook sign-in added to an existing account — from the
 * profile, by signing in with a confirmed email, or by completing a pending
 * link — and every one removed, is announced to the account's own address.
 * A brand-new account and a repeat of a connection already made are not news.
 */

beforeEach(fn () => Notification::fake());

it('emails the account when a provider is connected from the profile', function () {
    $user = User::factory()->create(['email' => 'ivan@example.com']);
    Socialite::fake('facebook', fakeSocialiteUser(['id' => 'fb-1', 'email' => 'ivan.personal@example.com']));

    $this->actingAs($user)->get(socialCallbackUrl('facebook'));

    Notification::assertSentTo(
        $user,
        SocialAccountConnectedNotification::class,
        fn (SocialAccountConnectedNotification $notification, array $channels): bool => $notification->provider === SocialProvider::Facebook
            && $notification->providerEmail === 'ivan.personal@example.com'
            && $channels === ['mail'],
    );
});

it('emails the account when a confirmed email signs straight in and links the provider', function () {
    $user = User::factory()->create(['email' => 'ivan@gmail.com']);
    Socialite::fake('google', fakeSocialiteUser(['id' => 'g-1', 'email' => 'ivan@gmail.com']));

    $this->get(socialCallbackUrl('google'));

    $this->assertAuthenticatedAs($user);
    Notification::assertSentTo($user, SocialAccountConnectedNotification::class);
});

it('emails the account when a pending link is completed by signing in', function () {
    $user = User::factory()->create(['email' => 'ivan@example.com']);

    $this->withSession([PendingSocialLink::SESSION_KEY => [
        'provider' => 'google',
        'provider_user_id' => 'g-1',
        'email' => 'ivan@example.com',
        'created_at' => now()->getTimestamp(),
    ]])->post('/login', ['email' => 'ivan@example.com', 'password' => 'password']);

    expect($user->socialAccounts()->count())->toBe(1);
    Notification::assertSentTo($user, SocialAccountConnectedNotification::class);
});

it('sends nothing for an account created through the provider', function () {
    Socialite::fake('google', fakeSocialiteUser(['id' => 'g-1', 'email' => 'ivan@gmail.com']));

    $this->get(socialCallbackUrl('google'));

    $this->assertAuthenticated();
    Notification::assertNotSentTo(User::query()->sole(), SocialAccountConnectedNotification::class);
});

it('sends nothing for a sign-in or a repeat connection of an identity already held', function () {
    $user = User::factory()->create(['email' => 'ivan@gmail.com']);
    SocialAccount::factory()->for($user)->google()->create(['provider_user_id' => 'g-1']);
    Socialite::fake('google', fakeSocialiteUser(['id' => 'g-1', 'email' => 'ivan@gmail.com']));

    $this->get(socialCallbackUrl('google'));
    $this->get(socialCallbackUrl('google'));

    $this->assertAuthenticatedAs($user);
    Notification::assertNothingSent();
});

it('sends nothing when the connection is refused', function () {
    $user = User::factory()->create(['email' => 'ivan@example.com']);
    User::factory()->create(['email' => 'maria@example.com']);
    Socialite::fake('google', fakeSocialiteUser(['id' => 'g-1', 'email' => 'maria@example.com']));

    $this->actingAs($user)->get(socialCallbackUrl('google'));

    Notification::assertNothingSent();
});

it('queues both emails, and only once the change is committed', function () {
    foreach ([
        new SocialAccountConnectedNotification(SocialProvider::Google, 'ivan@gmail.com'),
        new SocialAccountDisconnectedNotification(SocialProvider::Google, 'ivan@gmail.com'),
    ] as $notification) {
        expect($notification)->toBeInstanceOf(ShouldQueue::class)
            ->and($notification->afterCommit)->toBeTrue();
    }
});

it('writes the emails in the recipient\'s language', function () {
    $user = User::factory()->create(['locale' => 'ru']);
    SocialAccount::factory()->for($user)->google()->create(['provider_email' => 'ivan.personal@gmail.com']);
    SocialAccount::factory()->for($user)->facebook()->create();

    $this->actingAs($user)->delete(route('profile.connected-accounts.destroy', ['provider' => 'google']));

    Notification::assertSentTo(
        $user,
        SocialAccountDisconnectedNotification::class,
        fn ($notification, $channels, $notifiable, ?string $locale): bool => $locale === 'ru',
    );
});

it('names the provider, the provider account and where to review it', function (string $class, string $keys) {
    $user = User::factory()->create(['display_name' => 'Ivan']);
    $mail = (new $class(SocialProvider::Facebook, 'ivan.personal@example.com'))->toMail($user);

    expect($mail->subject)->toBe(__("{$keys}.subject", ['provider' => 'Facebook']))
        ->and($mail->greeting)->toBe(__('mail.greeting', ['name' => 'Ivan']))
        ->and($mail->introLines)->toBe([
            __("{$keys}.line", ['provider' => 'Facebook']),
            __('mail.social.account', ['provider' => 'Facebook', 'email' => 'ivan.personal@example.com']),
            __("{$keys}.not_you"),
        ])
        ->and($mail->actionText)->toBe(__('mail.social.action'))
        ->and($mail->actionUrl)->toBe(connectedAccountsUrl());
})->with([
    'connected' => [SocialAccountConnectedNotification::class, 'mail.social.connected'],
    'disconnected' => [SocialAccountDisconnectedNotification::class, 'mail.social.disconnected'],
]);

it('leaves the provider account line out when the provider shared no email', function () {
    $mail = (new SocialAccountConnectedNotification(SocialProvider::Facebook, null))->toMail(User::factory()->create());

    expect($mail->introLines)->toHaveCount(2);
});

it('never emails a deleted account', function () {
    $notification = new SocialAccountDisconnectedNotification(SocialProvider::Google, null);

    expect($notification->shouldSend(User::factory()->tombstoned()->create(), 'mail'))->toBeFalse()
        ->and($notification->shouldSend(User::factory()->create(), 'mail'))->toBeTrue();
});
