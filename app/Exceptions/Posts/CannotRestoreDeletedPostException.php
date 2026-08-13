<?php

namespace App\Exceptions\Posts;

use DomainException;

final class CannotRestoreDeletedPostException extends DomainException
{
    public static function becauseUserIsNotAllowed(): self
    {
        return new self('You cannot restore this post.');
    }

    public static function becausePostIsNotAuthorDeleted(): self
    {
        return new self('This post is not deleted.');
    }

    public static function becauseRestoreWindowExpired(): self
    {
        return new self('The restore window for this post has expired.');
    }

    public static function becauseDeletionStateIsInvalid(): self
    {
        return new self('This post cannot be restored.');
    }
}
