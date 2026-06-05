<?php

namespace App\Exceptions\Votes;

use DomainException;

final class CannotVoteCuisineException extends DomainException
{
    public static function becauseGuest(): self
    {
        return new self('Guests cannot vote on category.');
    }

    public static function becauseUserIsNotAllowed(): self
    {
        return new self('User is not allowed to vote on category.');
    }

    public static function becauseOwnPost(): self
    {
        return new self('You cannot vote on your own post.');
    }

    public static function becausePostIsNotPublic(): self
    {
        return new self('Post cannot receive category votes.');
    }

    public static function becauseCuisineIsInvalid(): self
    {
        return new self('Invalid category vote.');
    }

    public static function becauseRateLimited(string $message): self
    {
        return new self($message);
    }
}
