<?php

namespace App\Exceptions\Users;

use DomainException;

final class CannotUpdateUserAccountException extends DomainException
{
    public static function becauseUserIsNotAllowed(): self
    {
        return new self('User is not allowed to edit this account.');
    }
}
