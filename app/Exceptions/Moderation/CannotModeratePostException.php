<?php

namespace App\Exceptions\Moderation;

use DomainException;

final class CannotModeratePostException extends DomainException
{
    public static function becauseUserIsNotAllowed(): self
    {
        return new self(__('ui.moderation.errors.not_allowed'));
    }

    public static function becausePostStatusIsInvalid(): self
    {
        return new self(__('ui.moderation.errors.invalid_status'));
    }
}
