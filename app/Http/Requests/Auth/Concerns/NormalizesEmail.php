<?php

namespace App\Http\Requests\Auth\Concerns;

use Illuminate\Support\Str;

/**
 * Accounts keep their address in lowercase — registration and profile
 * updates require it, the admin and the console store it so — and an address
 * is matched exactly on PostgreSQL. Whatever case someone types to sign in or
 * to reset a password, it is compared lowercased and trimmed.
 */
trait NormalizesEmail
{
    protected function prepareForValidation(): void
    {
        $email = $this->input('email');

        if (is_string($email)) {
            $this->merge(['email' => Str::lower(trim($email))]);
        }
    }
}
