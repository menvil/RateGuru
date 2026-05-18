<?php

namespace App\Exceptions\Votes;

use DomainException;

final class CannotVoteCuisineException extends DomainException
{
    public static function becauseGuest(): self
    {
        return new self('Guests cannot vote on cuisine.');
    }

    public static function becauseUserIsNotAllowed(): self
    {
        return new self('User is not allowed to vote on cuisine.');
    }

    public static function becausePostIsNotPublic(): self
    {
        return new self('Post cannot receive cuisine votes.');
    }

    public static function becauseCuisineIsInvalid(): self
    {
        return new self('Invalid cuisine vote.');
    }
}
