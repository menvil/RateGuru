<?php

namespace App\Exceptions\Posts;

use DomainException;

final class CannotDeletePostException extends DomainException
{
    public static function becauseUserIsNotAllowed(): self
    {
        return new self(__('ui.post.delete_errors.not_allowed'));
    }

    public static function becausePostIsUnderModeration(): self
    {
        return new self(__('ui.post.delete_errors.under_moderation'));
    }

    public static function becausePostStateIsInvalid(): self
    {
        return new self(__('ui.post.delete_errors.invalid_state'));
    }
}
