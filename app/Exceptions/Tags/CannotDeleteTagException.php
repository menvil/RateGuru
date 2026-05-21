<?php

namespace App\Exceptions\Tags;

use DomainException;

final class CannotDeleteTagException extends DomainException
{
    public static function becauseUserIsNotAllowed(): self
    {
        return new self('User is not allowed to delete tags.');
    }

    public static function becauseTagIsUsedByPosts(): self
    {
        return new self('Tag is attached to posts and cannot be deleted.');
    }
}
