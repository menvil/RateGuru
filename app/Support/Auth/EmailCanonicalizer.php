<?php

namespace App\Support\Auth;

use Illuminate\Support\Str;

/**
 * The mailbox a Gmail address really delivers to. Gmail ignores dots and
 * everything after a plus, and treats googlemail.com as gmail.com, so one
 * person has endless spellings of one address. Null for any other provider,
 * whose rules for the same characters are its own.
 */
final class EmailCanonicalizer
{
    private const array GMAIL_DOMAINS = ['gmail.com', 'googlemail.com'];

    public static function canonical(?string $email): ?string
    {
        $email = Str::lower(trim((string) $email));
        $at = strrpos($email, '@');

        if ($at === false || ! in_array(substr($email, $at + 1), self::GMAIL_DOMAINS, true)) {
            return null;
        }

        $mailbox = str_replace('.', '', Str::before(substr($email, 0, $at), '+'));

        return $mailbox === '' ? null : $mailbox.'@gmail.com';
    }
}
