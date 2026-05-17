<?php

namespace App\Exceptions\Posts;

use DomainException;

final class CannotCreatePostException extends DomainException
{
    public static function becauseUserIsNotAllowed(): self
    {
        return new self('User is not allowed to create posts.');
    }
}
