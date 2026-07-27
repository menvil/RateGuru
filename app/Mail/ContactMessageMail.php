<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class ContactMessageMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $senderName,
        public readonly string $senderEmail,
        public readonly string $messageSubject,
        public readonly string $messageBody,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            replyTo: [
                new Address($this->senderEmail, $this->senderName),
            ],
            subject: '[RateGuru Contact] '.$this->messageSubject,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.contact-message',
        );
    }
}
