<?php

namespace App\Support\Auth;

use App\Models\User;
use Propaganistas\LaravelDisposableEmail\Facades\DisposableDomains;

/**
 * Whether an address someone is about to take is a throwaway one, or another
 * spelling of a Gmail mailbox an account already has. Only answers: whether
 * that is logged or refused is the screening mode's to say.
 */
final class EmailQualityCheck
{
    public const string DISPOSABLE = 'disposable_email';

    public const string GMAIL_ALIAS = 'gmail_alias';

    /** @return list<string> */
    public function reasonsFor(string $email, ?int $exceptUserId = null): array
    {
        $reasons = [];

        if (DisposableDomains::isDisposable($email)) {
            $reasons[] = self::DISPOSABLE;
        }

        $canonical = EmailCanonicalizer::canonical($email);

        if ($canonical !== null && User::query()
            ->where('email_canonical', $canonical)
            ->when($exceptUserId !== null, fn ($others) => $others->whereKeyNot($exceptUserId))
            ->exists()) {
            $reasons[] = self::GMAIL_ALIAS;
        }

        return $reasons;
    }

    /**
     * The error for the address field, in the words a mistyped address gets.
     *
     * @param  list<string>  $reasons
     * @return array<string, string>
     */
    public function refusal(array $reasons): array
    {
        $attribute = ['attribute' => __('validation.attributes.email')];

        return ['email' => in_array(self::GMAIL_ALIAS, $reasons, true)
            ? __('validation.unique', $attribute)
            : __('validation.email', $attribute)];
    }
}
