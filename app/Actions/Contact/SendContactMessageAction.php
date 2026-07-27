<?php

namespace App\Actions\Contact;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Mail\ContactMessageMail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

final class SendContactMessageAction
{
    /**
     * @param  array{name: string, email: string, subject: string, message: string}  $message
     */
    public function handle(array $message): void
    {
        /** @var array<int, string> $recipients */
        $recipients = User::query()
            ->where('role', UserRole::Admin)
            ->where('status', UserStatus::Active)
            ->pluck('email')
            ->filter(fn (mixed $email): bool => is_string($email) && $email !== '')
            ->unique()
            ->values()
            ->all();

        if ($recipients === []) {
            $fallback = config('mail.contact_to') ?: config('mail.from.address');

            if (is_string($fallback) && $fallback !== '') {
                $recipients = [$fallback];
            }
        }

        Mail::to($recipients)->queue(new ContactMessageMail(
            senderName: $message['name'],
            senderEmail: $message['email'],
            messageSubject: $message['subject'],
            messageBody: $message['message'],
        ));
    }
}
