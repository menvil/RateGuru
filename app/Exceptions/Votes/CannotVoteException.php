<?php

namespace App\Exceptions\Votes;

use DomainException;

final class CannotVoteException extends DomainException
{
    public static function becauseGuest(): self
    {
        return new self('Guests cannot vote.');
    }

    public static function becauseUserIsNotAllowed(): self
    {
        return new self('User is not allowed to vote.');
    }

    public static function becausePostIsNotPublic(): self
    {
        return new self('Post cannot be voted on.');
    }

    public static function becauseRateLimited(string $message): self
    {
        return new self($message);
    }
}
