<?php

use App\Actions\Auth\RegisterUserAction;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Password;

/**
 * Which language a message leaves in.
 *
 * The rule the product wants is one sentence: a person is written to in the
 * language they chose, and — when we have never been told — in the language of
 * the site they were looking at. Everything here is that sentence, split into
 * the cases where it used to be false.
 *
 * These assert the locale a notification is RENDERED in, which is the only
 * thing that decides what the recipient reads. Note that it is NOT
 * `$notification->locale`: that property is only populated by an explicit
 * ->locale() call, while NotificationSender resolves the preference at send
 * time and wraps the render in withLocale(). Asserting on the property would
 * pass for the wrong reason on a notification nobody localized.
 */

/** Records the locale it is rendered in, which is the whole contract. */
final class LocaleRecordingNotification extends Notification
{
    public static ?string $renderedIn = null;

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        self::$renderedIn = app()->getLocale();

        return (new MailMessage)->line('probe');
    }
}

beforeEach(function () {
    config()->set('locales.supported', [
        'en' => ['label' => 'English', 'native' => 'English'],
        'ru' => ['label' => 'Russian', 'native' => 'Русский'],
        'bg' => ['label' => 'Bulgarian', 'native' => 'Български'],
    ]);
    config()->set('locales.fallback', 'en');

    LocaleRecordingNotification::$renderedIn = null;
});

it('prefers the language the account chose', function () {
    expect(User::factory()->create(['locale' => 'ru'])->preferredLocale())->toBe('ru');
});

it('has no preference when the account never chose one', function () {
    // NULL is not "English". It means "no preference", so Laravel renders in
    // whatever locale the request is already in — which is today's behaviour,
    // and the reason adding this contract cannot regress anyone.
    expect(User::factory()->create(['locale' => null])->preferredLocale())->toBeNull();
});

it('has no preference when the stored language is no longer supported', function () {
    $user = User::factory()->create(['locale' => 'ru']);

    config()->set('locales.supported', ['en' => ['label' => 'English', 'native' => 'English']]);

    expect($user->preferredLocale())->toBeNull();
});

it('renders mail in the account language, whatever the request locale is', function () {
    $user = User::factory()->create(['locale' => 'ru']);

    app()->setLocale('en');
    $user->notify(new LocaleRecordingNotification);

    expect(LocaleRecordingNotification::$renderedIn)->toBe('ru');
});

it('renders mail in the site language when the account has no preference', function () {
    $user = User::factory()->create(['locale' => null]);

    app()->setLocale('bg');
    $user->notify(new LocaleRecordingNotification);

    expect(LocaleRecordingNotification::$renderedIn)->toBe('bg');
});

it('restores the request locale after rendering', function () {
    // withLocale() is scoped; a mail to a Russian reader must not leave the
    // rest of the request rendering in Russian for everyone else.
    $user = User::factory()->create(['locale' => 'ru']);

    app()->setLocale('en');
    $user->notify(new LocaleRecordingNotification);

    expect(app()->getLocale())->toBe('en');
});

it('reaches a password reset through that same preference', function () {
    // The case this change exists for: the person is NOT logged in, so the
    // request locale is whatever their browser is set to — but we know exactly
    // who they are, because we just looked them up by email.
    $user = User::factory()->create(['email' => 'reader@example.test', 'locale' => 'ru']);

    app()->setLocale('en');

    expect(fn () => Password::sendResetLink(['email' => $user->email]))->not->toThrow(Exception::class)
        ->and($user->fresh()->preferredLocale())->toBe('ru');
});

it('stores the site language on the account at registration', function (string $locale) {
    // Without this the column stays NULL for every new account, preferredLocale()
    // has nothing to prefer, and the contract above is inert for exactly the
    // people it was added for.
    app()->setLocale($locale);

    $user = app(RegisterUserAction::class)->execute([
        'name' => 'Reader',
        'email' => "reader-{$locale}@example.test",
        'password' => 'password-that-is-long-enough',
    ]);

    expect($user->locale)->toBe($locale)
        ->and($user->preferredLocale())->toBe($locale);
})->with(['en', 'ru', 'bg']);

it('stores a supported language even when the request locale is not one', function () {
    app()->setLocale('de');

    $user = app(RegisterUserAction::class)->execute([
        'name' => 'Reader',
        'email' => 'reader-unsupported@example.test',
        'password' => 'password-that-is-long-enough',
    ]);

    expect($user->locale)->toBe('en');
});
