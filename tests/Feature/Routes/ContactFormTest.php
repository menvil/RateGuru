<?php

use App\Mail\ContactMessageMail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

it('queues a contact message to active administrators', function () {
    Mail::fake();

    $admin = User::factory()->admin()->create([
        'email' => 'admin@example.test',
    ]);
    $inactiveAdmin = User::factory()->admin()->banned()->create([
        'email' => 'inactive-admin@example.test',
    ]);
    $regularUser = User::factory()->create([
        'email' => 'member@example.test',
    ]);

    $this->post(route('pages.contact.submit'), [
        'name' => 'Jane Visitor',
        'email' => 'jane@example.test',
        'subject' => 'Partnership question',
        'message' => 'Could an administrator contact me about a partnership?',
    ])
        ->assertRedirect(route('pages.contact'))
        ->assertSessionHas('contact_status', __('ui.contact.sent'));

    Mail::assertQueued(ContactMessageMail::class, function (ContactMessageMail $mail) use ($admin, $inactiveAdmin, $regularUser): bool {
        return $mail->hasTo($admin->email)
            && ! $mail->hasTo($inactiveAdmin->email)
            && ! $mail->hasTo($regularUser->email)
            && $mail->hasReplyTo('jane@example.test', 'Jane Visitor')
            && $mail->senderName === 'Jane Visitor'
            && $mail->senderEmail === 'jane@example.test'
            && $mail->messageSubject === 'Partnership question'
            && $mail->messageBody === 'Could an administrator contact me about a partnership?';
    });
});

it('validates contact messages before queuing mail', function () {
    Mail::fake();
    User::factory()->admin()->create();

    $this->from(route('pages.contact'))
        ->post(route('pages.contact.submit'), [
            'name' => '',
            'email' => 'not-an-email',
            'subject' => '',
            'message' => '',
        ])
        ->assertRedirect(route('pages.contact'))
        ->assertSessionHasErrors(['name', 'email', 'subject', 'message']);

    Mail::assertNothingQueued();
});

it('never treats the global sender address as a contact mailbox', function () {
    Mail::fake();
    config()->set('mail.contact_to', null);
    config()->set('mail.from.address', 'noreply@staging.invalid');

    $this->post(route('pages.contact.submit'), [
        'name' => 'Fallback Sender',
        'email' => 'sender@example.test',
        'subject' => 'Nowhere to go',
        'message' => 'There is no administrator and no contact mailbox.',
    ]);

    // MAIL_FROM_ADDRESS is where this project sends FROM — in the deployed
    // targets a noreply address. Delivering a visitor's question there means
    // nobody reads the reply, or it bounces.
    Mail::assertNothingQueued();
});

it('tells the visitor when a contact message has nowhere to go, instead of claiming it was sent', function () {
    Mail::fake();
    config()->set('mail.contact_to', null);
    config()->set('mail.from.address', 'noreply@staging.invalid');

    $this->post(route('pages.contact.submit'), [
        'name' => 'Fallback Sender',
        'email' => 'sender@example.test',
        'subject' => 'Nowhere to go',
        'message' => 'There is no administrator and no contact mailbox.',
    ])
        ->assertRedirect(route('pages.contact'))
        ->assertSessionMissing('contact_status')
        ->assertSessionHas('contact_error', __('ui.contact.undeliverable'));

    // And what was typed survives. Asserted on the re-rendered VALUES, not on
    // the error box: the box proves the failure was reported, and would still
    // render with ->withInput() removed and the visitor's text thrown away.
    $this->followingRedirects()
        ->post(route('pages.contact.submit'), [
            'name' => 'Fallback Sender',
            'email' => 'sender@example.test',
            'subject' => 'Nowhere to go',
            'message' => 'There is no administrator and no contact mailbox.',
        ])
        ->assertSee('data-testid="contact-error"', false)
        ->assertSee('value="Fallback Sender"', false)
        ->assertSee('value="sender@example.test"', false)
        ->assertSee('value="Nowhere to go"', false)
        ->assertSee('There is no administrator and no contact mailbox.', false);
});

it('still reaches an administrator when one exists, whatever the contact mailbox says', function () {
    Mail::fake();
    config()->set('mail.contact_to', null);

    $admin = User::factory()->admin()->create(['email' => 'admin@example.test']);

    $this->post(route('pages.contact.submit'), [
        'name' => 'Jane Visitor',
        'email' => 'jane@example.test',
        'subject' => 'Still works',
        'message' => 'An administrator is the first choice and needs no configuration.',
    ])->assertSessionHas('contact_status');

    Mail::assertQueued(
        ContactMessageMail::class,
        fn (ContactMessageMail $mail): bool => $mail->hasTo($admin->email),
    );
});

it('takes an address typed with capitals as it was typed', function () {
    Mail::fake();
    User::factory()->admin()->create();

    $this->post(route('pages.contact.submit'), [
        'name' => 'Jane Visitor',
        'email' => 'Jane.Visitor@Example.test',
        'subject' => 'Hello',
        'message' => 'A question.',
    ])->assertSessionHasNoErrors();

    Mail::assertQueued(ContactMessageMail::class, fn (ContactMessageMail $mail): bool => $mail->hasReplyTo('Jane.Visitor@Example.test', 'Jane Visitor'));
});

it('refuses an address the mailer could not reply to, rather than failing in the queue', function (string $email) {
    Mail::fake();
    User::factory()->admin()->create();

    $this->post(route('pages.contact.submit'), [
        'name' => 'Jane Visitor',
        'email' => $email,
        'subject' => 'Hello',
        'message' => 'A question.',
    ])->assertSessionHasErrors('email');

    Mail::assertNothingQueued();
})->with([
    'a comment in the address' => 'jane(work)@example.test',
    'a comment before the address' => '(work)jane@example.test',
    'folded whitespace in a quoted name' => "\"jane\r\n doe\"@example.test",
]);
