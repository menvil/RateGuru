<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\URL;

/**
 * What the recipient actually reads.
 *
 * RecipientLocaleTest proves the right locale is selected; this proves there is
 * something to read in it. The two were separate failures before: the locale
 * was often right and the text was English anyway, because the framework's
 * strings were only translatable through lang/{locale}.json keyed by English
 * prose, and those keys were not there.
 *
 * Rendering rather than string-matching is the point. A missing key does not
 * throw in Laravel — __() returns the key itself — so an untranslated email
 * ships silently. Asserting that no raw "mail." key survives the render is what
 * turns that into a failure.
 */

/** @return array{subject: string, body: string} */
function renderedMail(object $notification, User $user): array
{
    $mail = $notification->toMail($user);
    $rendered = $mail->render();

    return [
        'subject' => (string) $mail->subject,
        'body' => is_string($rendered) ? $rendered : (string) $rendered->toHtml(),
    ];
}

dataset('supported locales', ['en', 'ru', 'bg']);

it('renders the verification email with no untranslated keys', function (string $locale) {
    app()->setLocale($locale);
    $user = User::factory()->unverified()->create(['locale' => $locale, 'name' => 'Reader']);

    $mail = renderedMail(new VerifyEmail, $user);

    expect($mail['subject'])->not->toContain('mail.')
        ->and($mail['body'])->not->toContain('mail.verify')
        ->and($mail['body'])->not->toContain('mail.greeting')
        ->and($mail['body'])->not->toContain('mail.salutation')
        // The framework's own English must be gone, not merely joined.
        ->and($mail['subject'])->not->toBe('Verify Email Address');
})->with('supported locales');

it('renders the password reset email with no untranslated keys', function (string $locale) {
    app()->setLocale($locale);
    $user = User::factory()->create(['locale' => $locale, 'name' => 'Reader']);

    $mail = renderedMail(new ResetPassword('a-token'), $user);

    expect($mail['subject'])->not->toContain('mail.')
        ->and($mail['body'])->not->toContain('mail.reset')
        ->and($mail['subject'])->not->toBe('Reset Password');
})->with('supported locales');

it('actually differs between languages', function () {
    // A translation file that was copied and never translated would pass every
    // "no raw key" assertion above while shipping English to everyone.
    $user = User::factory()->unverified()->create(['name' => 'Reader']);

    $subjects = collect(['en', 'ru', 'bg'])->mapWithKeys(function (string $locale) use ($user): array {
        app()->setLocale($locale);

        return [$locale => renderedMail(new VerifyEmail, $user)['subject']];
    });

    expect($subjects->unique())->toHaveCount(3);
});

it('keeps the password reset link identical to the framework default', function () {
    // Replacing the template must change the wording and nothing about where
    // the link goes.
    app()->setLocale('ru');
    $user = User::factory()->create(['email' => 'reader@example.test']);

    $body = renderedMail(new ResetPassword('a-token'), $user)['body'];
    $expected = URL::to(route('password.reset', [
        'token' => 'a-token',
        'email' => $user->email,
    ], false));

    expect($body)->toContain(e($expected));
});

it('greets a user with no display name without an empty salutation', function () {
    app()->setLocale('en');
    $user = User::factory()->unverified()->create(['name' => 'Reader', 'display_name' => null]);

    expect(renderedMail(new VerifyEmail, $user)['body'])
        ->toContain('Reader')
        ->not->toContain('Hello, !');
});
