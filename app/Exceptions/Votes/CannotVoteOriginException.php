<?php

namespace App\Exceptions\Votes;

use DomainException;

final class CannotVoteOriginException extends DomainException
{
    public static function becauseGuest(): self
    {
        return new self('Guests cannot vote on source.');
    }

    public static function becauseUserIsNotAllowed(): self
    {
        return new self('User is not allowed to vote on source.');
    }

    public static function becauseOwnPost(): self
    {
        return new self('You cannot vote on your own post.');
    }

    public static function becausePostIsNotPublic(): self
    {
        return new self('Post cannot receive source votes.');
    }

    public static function becauseOriginIsInvalid(): self
    {
        return new self('Invalid source vote.');
    }

    public static function becauseRateLimited(string $message): self
    {
        return new self($message);
    }
}
