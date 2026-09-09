<?php

namespace App\Actions\Contact;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Mail\ContactMessageMail;
use App\Models\User;
use App\Support\Locale\LocaleManager;
use Illuminate\Support\Facades\Mail;

final class SendContactMessageAction
{
    public function __construct(private readonly LocaleManager $locales) {}

    /**
     * @param  array{name: string, email: string, subject: string, message: string}  $message
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
                $this->mailFor($message, $admin->preferredLocale() ?? $this->locales->fallback()),
            );
        }

        if ($admins->isNotEmpty()) {
            return;
        }

        // No administrator to write to: fall back to the configured address,
        // which is a mailbox rather than an account, so there is no preference
        // to honour and the fallback locale is the only honest choice.
        $fallback = config('mail.contact_to') ?: config('mail.from.address');

        if (is_string($fallback) && $fallback !== '') {
            Mail::to($fallback)->queue($this->mailFor($message, $this->locales->fallback()));
        }
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
