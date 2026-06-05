<?php

namespace App\Exceptions\Rating;

use DomainException;

final class CannotVoteForRatingOptionException extends DomainException
{
    public static function becauseGuest(): self
    {
        return new self('Guests cannot vote on rating options.');
    }

    public static function becauseUserIsNotAllowed(): self
    {
        return new self('User is not allowed to vote on rating options.');
    }

    public static function becausePostIsNotPublic(): self
    {
        return new self('Post cannot receive rating votes.');
    }

    public static function becauseOwnPost(): self
    {
        return new self('You cannot vote on your own post.');
    }

    public static function becauseOptionIsInactive(): self
    {
        return new self('Rating option is inactive.');
    }

    public static function becauseGroupIsInactive(): self
    {
        return new self('Rating group is inactive.');
    }

    public static function becauseRateLimited(string $message): self
    {
        return new self($message);
    }
}
