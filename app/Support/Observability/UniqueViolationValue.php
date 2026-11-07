<?php

namespace App\Support\Observability;

/**
 * The offending value of a unique-constraint violation, taken out of the
 * driver's message. Almost every exception message RateGuru produces is
 * static text, but a driver-level unique violation is not: PostgreSQL appends
 * `DETAIL: Key (email)=(someone@example.com) already exists.` and MySQL
 * `Duplicate entry 'someone@example.com' for key '...'`. Registration and
 * profile updates can both raise one, so the column name is kept — that is
 * the diagnosis — and the value is not. Nightwatch and Sentry both send
 * messages through this.
 */
final class UniqueViolationValue
{
    public static function redact(string $message, string $replacement): string
    {
        // Anchored to the last text the driver puts after the value, not to the
        // first: an address may hold a ')' or an apostrophe, or the driver's own
        // words (o'brien@…, a) already exists@…), and the part after them would
        // still be sent. The driver adds that text once, and the masked SQL
        // after it holds only placeholders.
        $message = (string) preg_replace(
            '/(Key \((?:[^)]*)\)=\().*(\) already exists)/s',
            '$1'.$replacement.'$2',
            $message,
        );

        return (string) preg_replace(
            "/(Duplicate entry ').*(' for key ')/s",
            '$1'.$replacement.'$2',
            $message,
        );
    }
}
