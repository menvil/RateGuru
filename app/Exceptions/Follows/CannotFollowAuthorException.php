<?php

namespace App\Exceptions\Follows;

use RuntimeException;

class CannotFollowAuthorException extends RuntimeException
{
    public static function authorNotViewable(): self
    {
        return new self('This author cannot be followed.');
    }
}
