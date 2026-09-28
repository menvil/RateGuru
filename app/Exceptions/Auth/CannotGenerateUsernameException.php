<?php

namespace App\Exceptions\Auth;

use RuntimeException;

/**
 * No username could be settled for a new account: the generator ran out of
 * candidates, or every candidate it produced lost the race to a concurrent
 * registration. Typed registration presents it as a validation error on the
 * name field; social sign-in has no field to correct and leaves it to the
 * normal exception handler.
 */
class CannotGenerateUsernameException extends RuntimeException
{
    public static function becauseGeneratorFailed(RuntimeException $exception): self
    {
        return new self($exception->getMessage(), 0, $exception);
    }

    public static function afterExhaustingAttempts(): self
    {
        return new self('Unable to create a unique username. Please try a different name.');
    }
}
