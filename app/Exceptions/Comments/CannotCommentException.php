<?php

namespace App\Exceptions\Comments;

use DomainException;

final class CannotCommentException extends DomainException
{
    public static function becauseGuest(): self
    {
        return new self('Guests cannot comment.');
    }

    public static function becauseUserIsNotAllowed(): self
    {
        return new self('User is not allowed to comment.');
    }

    public static function becausePostIsNotPublic(): self
    {
        return new self('Post cannot receive comments.');
    }

    public static function becauseBodyIsInvalid(string $message = 'Comment body is invalid.'): self
    {
        return new self($message);
    }

    public static function becauseRateLimited(string $message): self
    {
        return new self($message);
    }
}
