<?php

namespace App\Exceptions\Posts;

use DomainException;

final class CannotRestoreDeletedPostException extends DomainException
{
    public static function becauseUserIsNotAllowed(): self
    {
        return new self(__('ui.recently_deleted.errors.not_allowed'));
    }

    public static function becausePostIsNotAuthorDeleted(): self
    {
        return new self(__('ui.recently_deleted.errors.not_deleted'));
    }

    public static function becauseRestoreWindowExpired(): self
    {
        return new self(__('ui.recently_deleted.errors.window_expired'));
    }

    public static function becauseDeletionStateIsInvalid(): self
    {
        return new self(__('ui.recently_deleted.errors.invalid_state'));
    }
}
