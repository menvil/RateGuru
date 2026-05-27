<?php

namespace App\Exceptions\Votes;

use DomainException;

final class CannotVoteCommentException extends DomainException
{
    public static function becauseGuest(): self
    {
        return new self('Guests cannot vote on comments.');
    }

    public static function becauseUserIsNotAllowed(): self
    {
        return new self('User is not allowed to vote on comments.');
    }

    public static function becauseCommentIsNotVisible(): self
    {
        return new self('Comment cannot be voted on.');
    }

    public static function becauseOwnComment(): self
    {
        return new self('You cannot vote on your own comment.');
    }

    public static function becauseRateLimited(string $message): self
    {
        return new self($message);
    }
}
