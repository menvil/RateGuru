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
})->with(representativeLocales());

it('takes its wording from the catalog of the running release, with nothing stored', function () {
    // Mail wording is the application's, not the project's: a release that
    // changes lang/{locale}/mail.php changes the next mail, and no database
    // copy or backfill is involved.
    [$locale] = twoTranslatedLocales();
    app('translator')->addLines(['mail.verify.subject' => 'Wording from a later release'], $locale);
    app()->setLocale($locale);

    $mail = renderedMail(new VerifyEmail, User::factory()->unverified()->create(['locale' => $locale]));

    expect($mail['subject'])->toBe('Wording from a later release');
});

it('renders the password reset email with no untranslated keys', function (string $locale) {
    app()->setLocale($locale);
    $user = User::factory()->create(['locale' => $locale, 'name' => 'Reader']);

    $mail = renderedMail(new ResetPassword('a-token'), $user);

    expect($mail['subject'])->not->toContain('mail.')
        ->and($mail['body'])->not->toContain('mail.reset')
        ->and($mail['subject'])->not->toBe('Reset Password');
})->with(representativeLocales());

it('writes the lines around the message in the recipient language too, in every language', function () {
    // The button fallback under the action and the footer come from the
    // notification layout, not the message — before it was ours they were the
    // framework's English sentences in every language. Every language goes
    // through the real layout, in one test: what one language can get wrong
    // here is its own text, which the layout renders as Markdown.
    foreach (supportedLocales() as $locale) {
        app()->setLocale($locale);
        $user = User::factory()->create(['locale' => $locale, 'name' => 'Reader']);

        $body = renderedMail(new ResetPassword('a-token'), $user)['body'];
        $fallback = __('mail.action_fallback', ['action' => __('mail.reset.action')]);

        expect(str_contains($body, e(__('mail.rights_reserved'))))->toBeTrue("the footer in {$locale}")
            ->and(str_contains(html_entity_decode($body, ENT_QUOTES | ENT_HTML5), html_entity_decode($fallback, ENT_QUOTES | ENT_HTML5)))->toBeTrue("the button fallback in {$locale}");

        if ($locale !== 'en') {
            expect(str_contains($body, 'All rights reserved'))->toBeFalse("the footer in {$locale} is still English")
                ->and(str_contains($body, 'having trouble clicking'))->toBeFalse("the button fallback in {$locale} is still English");
        }
    }
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
