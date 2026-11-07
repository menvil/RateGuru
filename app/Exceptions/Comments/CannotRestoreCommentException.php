<?php

namespace App\Exceptions\Comments;

use DomainException;

final class CannotRestoreCommentException extends DomainException
{
    public static function becauseUserIsNotAllowed(): self
    {
        return new self('User is not allowed to restore comments.');
    }

    public static function becauseCommentStatusIsInvalid(): self
    {
        return new self('Comment cannot be restored from its current status.');
    }
}
