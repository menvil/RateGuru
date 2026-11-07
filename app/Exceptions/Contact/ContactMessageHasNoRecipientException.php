<?php

namespace App\Exceptions\Contact;

use RuntimeException;

/**
 * Nobody to deliver a contact message to: the project has no active
 * administrator with an address, and no explicit contact mailbox is
 * configured. A deployment problem, never the visitor's mistake — but the
 * visitor is the one who must not be told their message was sent.
 */
final class ContactMessageHasNoRecipientException extends RuntimeException
{
    public static function becauseNoneIsConfigured(): self
    {
        return new self('No active administrator and no MAIL_CONTACT_TO address to deliver a contact message to.');
    }
}
