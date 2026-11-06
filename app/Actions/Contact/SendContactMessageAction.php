<?php

namespace App\Actions\Contact;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Exceptions\Contact\ContactMessageHasNoRecipientException;
use App\Mail\ContactMessageMail;
use App\Models\User;
use App\Support\Locale\LocaleManager;
use App\Support\Observability\DomainLogger;
use Illuminate\Support\Facades\Mail;

final class SendContactMessageAction
{
    public function __construct(
        private readonly LocaleManager $locales,
        private readonly DomainLogger $logger,
    ) {}

    /**
     * @param  array{name: string, email: string, subject: string, message: string}  $message
     *
     * @throws ContactMessageHasNoRecipientException when the project has
     *                                               nowhere to deliver to. Never swallowed: a visitor who is told their
     *                                               message was sent has no reason to try again, so a dropped message is
     *                                               lost for good.
     */
    public function handle(array $message): void
    {
        $admins = User::query()
            ->where('role', UserRole::Admin)
            ->where('status', UserStatus::Active)
            ->get()
            ->filter(fn (User $admin): bool => trim($admin->email) !== '')
            ->unique('email')
            ->values();

        // One message per administrator rather than one message to all of
        // them, because each is written in the language that administrator
        // chose. A single Mail::to([...]) can only be rendered once, and the
        // one locale it would be rendered in is the queue worker's — which is
        // the fallback, and nobody's preference.
        //
        // The cost is one queued job per administrator instead of one. For the
        // number of administrators a project has, that is nothing next to
        // reading your own admin mail in a language you did not pick.
        foreach ($admins as $admin) {
            Mail::to($admin->email)->queue(
                $this->mailFor($message, $admin->preferredLocale() ?? $this->locales->default()),
            );
        }

        if ($admins->isNotEmpty()) {
            return;
        }

        // No administrator to write to: fall back to the configured contact
        // mailbox, which is a mailbox rather than an account, so there is no
        // preference to honour and the default language is the only honest
        // choice.
        //
        // Deliberately NOT falling back further to mail.from.address. That is
        // the address this project sends FROM — in the deployed targets a
        // noreply one — and treating a sender as an inbox means contact
        // messages arrive where nobody reads replies, or bounce, with the
        // visitor told they were delivered either way.
        $contactMailbox = trim((string) config('mail.contact_to'));

        if ($contactMailbox === '') {
            $this->logger->error('contact.no_recipient', [
                // The one fact that is not already in the event name: whether
                // there are administrator accounts that were all excluded
                // (suspended, or without an address) or none at all. No visitor
                // PII — this is a deployment fault, and the record of it should
                // not become a copy of the message that triggered it.
                'admin_accounts' => User::query()->where('role', UserRole::Admin)->count(),
            ]);

            throw ContactMessageHasNoRecipientException::becauseNoneIsConfigured();
        }

        Mail::to($contactMailbox)->queue($this->mailFor($message, $this->locales->default()));
    }

    /**
     * @param  array{name: string, email: string, subject: string, message: string}  $message
     */
    private function mailFor(array $message, string $locale): ContactMessageMail
    {
        return (new ContactMessageMail(
            senderName: $message['name'],
            senderEmail: $message['email'],
            messageSubject: $message['subject'],
            messageBody: $message['message'],
        ))->locale($locale);
    }
}
