<?php

namespace App\Exceptions\Posts;

use DomainException;

final class CannotDeletePostException extends DomainException
{
    public static function becauseUserIsNotAllowed(): self
    {
        return new self('You cannot delete this post.');
    }
}
