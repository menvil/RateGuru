<?php

namespace App\Exceptions\Moderation;

use DomainException;

final class CannotModerateUserException extends DomainException
{
    public static function becauseUserIsNotAllowed(): self
    {
        return new self('User is not allowed to moderate users.');
    }

    public static function becauseTargetIsProtected(): self
    {
        return new self('Target user cannot be moderated.');
    }
}
