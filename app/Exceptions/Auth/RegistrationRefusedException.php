<?php

namespace App\Exceptions\Auth;

use App\Enums\SignupRefusal;
use Illuminate\Validation\ValidationException;

/**
 * A sign-up the screening refused (ScreenRegistrationAction, enforce mode):
 * the same answer the form always gets, carrying why for the registration
 * funnel (SignupRefusal) — never what was typed.
 */
final class RegistrationRefusedException extends ValidationException
{
    /** @var list<SignupRefusal> */
    public array $refusals = [];

    /**
     * @param  list<SignupRefusal>  $refusals
     * @param  array<string, string|list<string>>  $messages
     */
    public static function because(array $refusals, array $messages): self
    {
        $exception = self::withMessages($messages);
        $exception->refusals = $refusals;

        return $exception;
    }
}
