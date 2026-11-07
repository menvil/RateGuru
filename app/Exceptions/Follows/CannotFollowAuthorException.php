<?php

namespace App\Exceptions\Follows;

use RuntimeException;

class CannotFollowAuthorException extends RuntimeException
{
    public static function authorNotViewable(): self
    {
        return new self('This author cannot be followed.');
    }

    public static function followerNotAllowed(): self
    {
        return new self('Your account is not allowed to follow authors.');
    }
}
