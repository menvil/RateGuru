<?php

namespace App\Exceptions\Comments;

use DomainException;

final class CannotDeleteCommentException extends DomainException
{
    public static function becauseUserIsNotAllowed(): self
    {
        return new self('User is not allowed to delete this comment.');
    }
}
