<?php

namespace App\Exceptions\Profile;

use RuntimeException;

class CannotUpdateProfileException extends RuntimeException
{
    public static function becauseAccountIsNotEditable(): self
    {
        return new self('This account can no longer be edited.');
    }
}
