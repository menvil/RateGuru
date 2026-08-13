<?php

namespace App\Exceptions\Posts;

use DomainException;

final class CannotDeletePostException extends DomainException
{
    public static function becauseUserIsNotAllowed(): self
    {
        return new self('You cannot delete this post.');
    }

    public static function becausePostIsUnderModeration(): self
    {
        return new self('This post is under moderation and cannot be deleted.');
    }

    public static function becausePostStateIsInvalid(): self
    {
        return new self('This post cannot be deleted.');
    }
}
