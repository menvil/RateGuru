<?php

namespace App\Exceptions\Comments;

use DomainException;

final class CannotHideCommentException extends DomainException
{
    public static function becauseUserIsNotAllowed(): self
    {
        return new self('User is not allowed to hide comments.');
    }
}
