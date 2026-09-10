<?php

use App\Actions\Contact\SendContactMessageAction;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Mail\ContactMessageMail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

/**
 * Administrators read their own mail in their own language too.
 *
 * This one is queued, which is what made it the worst of the three: a queued
 * mailable renders in the worker's locale, and the worker has no request and no
 * recipient — so it rendered in the fallback for everyone, forever, regardless
 * of what any administrator had chosen.
 */

/** @param array<string, string> $overrides */
function sendContactMessage(array $overrides = []): void
{
    app(SendContactMessageAction::class)->handle(array_merge([
        'name' => 'Visitor',
        'email' => 'visitor@example.test',
        'subject' => 'A question',
        'message' => 'Body of the question.',
    ], $overrides));
}

function admin(string $locale, string $email): User
{
    return User::factory()->create([
        'role' => UserRole::Admin,
        'status' => UserStatus::Active,
        'locale' => $locale,
        'email' => $email,
    ]);
}

beforeEach(fn () => Mail::fake());

it('writes to each administrator in their own language', function () {
    admin('ru', 'ru-admin@example.test');
    admin('bg', 'bg-admin@example.test');

    sendContactMessage();

    // One message per administrator, each pinned to that administrator's
    // language — a single message to a list could only be rendered once.
    foreach (['ru-admin@example.test' => 'ru', 'bg-admin@example.test' => 'bg'] as $email => $locale) {
        Mail::assertQueued(
            ContactMessageMail::class,
            fn (ContactMessageMail $mail): bool => $mail->hasTo($email) && $mail->locale === $locale,
        );
    }

    Mail::assertQueuedCount(2);
});

it('falls back for an administrator who never chose a language', function () {
    admin('ru', 'chose@example.test');
    $undecided = admin('ru', 'undecided@example.test');
    $undecided->update(['locale' => null]);

    sendContactMessage();

    Mail::assertQueued(
        ContactMessageMail::class,
        fn (ContactMessageMail $mail): bool => $mail->hasTo('undecided@example.test')
            && $mail->locale === config('locales.fallback'),
    );
});

it('still reaches the configured mailbox when there is no administrator', function () {
    config()->set('mail.contact_to', 'inbox@example.test');

    sendContactMessage();

    // A mailbox is not an account, so there is no preference to honour and the
    // fallback is the only honest choice.
    Mail::assertQueued(
        ContactMessageMail::class,
        fn (ContactMessageMail $mail): bool => $mail->hasTo('inbox@example.test')
            && $mail->locale === config('locales.fallback'),
    );

    Mail::assertQueuedCount(1);
});

it('does not write to a suspended or non-admin account', function () {
    admin('ru', 'active@example.test');
    User::factory()->create(['role' => UserRole::Admin, 'status' => UserStatus::Banned, 'email' => 'banned@example.test']);
    User::factory()->create(['role' => UserRole::User, 'email' => 'reader@example.test']);

    sendContactMessage();

    Mail::assertQueuedCount(1);
    Mail::assertQueued(ContactMessageMail::class, fn (ContactMessageMail $mail): bool => $mail->hasTo('active@example.test'));
});

it('renders subject and body with no untranslated keys, in every language', function (string $locale) {
    app()->setLocale($locale);

    $mail = (new ContactMessageMail(
        senderName: 'Visitor',
        senderEmail: 'visitor@example.test',
        messageSubject: 'A question',
        messageBody: 'Body of the question.',
    ))->locale($locale);

    $rendered = $mail->render();

    expect($rendered)->not->toContain('mail.contact')
        ->and($rendered)->toContain('lang="'.$locale.'"')
        // The message itself must survive translation untouched.
        ->and($rendered)->toContain('Body of the question.');
})->with(['en', 'ru', 'bg']);
