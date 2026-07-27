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

it('uses the configured contact recipient when there are no active administrators', function () {
    Mail::fake();
    config()->set('mail.contact_to', 'owner@example.test');

    $this->post(route('pages.contact.submit'), [
        'name' => 'Fallback Sender',
        'email' => 'sender@example.test',
        'subject' => 'Contact fallback',
        'message' => 'This should reach the configured project owner.',
    ])->assertRedirect(route('pages.contact'));

    Mail::assertQueued(
        ContactMessageMail::class,
        fn (ContactMessageMail $mail): bool => $mail->hasTo('owner@example.test'),
    );
});
