<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Normalizer;

/**
 * Refuses a person's name that carries a link: a URL scheme, a `www.` host
 * or a Markdown link. Compatibility forms are folded first, so a full-width
 * `：／／` is the `://` it displays as. An ordinary dotted name stays valid:
 * "J.R. Smith", or "Awww." where `www.` is the end of a word.
 *
 * A value equal to the one the account already holds passes, so somebody
 * whose old name would not pass today can still save the rest of a form.
 */
final class ContainsNoLink implements ValidationRule
{
    private const string LINK = '~://|(?<![\p{L}\p{N}])www\.|\]\s*\(~iu';

    public function __construct(private readonly ?string $current = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === $this->current) {
            return;
        }

        $folded = Normalizer::normalize($value, Normalizer::FORM_KC);

        // Text that cannot be normalized or matched is not valid UTF-8.
        if ($folded === false || preg_match(self::LINK, $folded) !== 0) {
            $fail('validation.regex')->translate();
        }
    }
}
