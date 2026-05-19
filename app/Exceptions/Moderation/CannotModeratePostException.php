<?php

namespace App\Exceptions\Moderation;

use DomainException;

final class CannotModeratePostException extends DomainException
{
    public static function becauseUserIsNotAllowed(): self
    {
        return new self('User is not allowed to moderate posts.');
    }

    public static function becausePostStatusIsInvalid(): self
    {
        return new self('Post status is invalid for this moderation action.');
    }
}
