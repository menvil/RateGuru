<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use SensitiveParameter;

/**
 * Refuses a password holding a null character. Bcrypt answers one with an
 * error when hashing, and when checking reads only as far as that character,
 * so "secret\0anything" would pass as "secret".
 */
final class ContainsNoNullCharacter implements ValidationRule
{
    public function validate(string $attribute, #[SensitiveParameter] mixed $value, Closure $fail): void
    {
        if (is_string($value) && str_contains($value, "\0")) {
            $fail('validation.not_regex')->translate();
        }
    }
}
