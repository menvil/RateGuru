<?php

namespace App\Exceptions\Posts;

use DomainException;

final class CannotCreatePostException extends DomainException
{
    public static function becauseUserIsNotAllowed(): self
    {
        return new self('User is not allowed to create posts.');
    }

    public static function becauseCategoryIsInvalid(): self
    {
        return new self('The selected category is not available.');
    }

    public static function becauseAuthorAnswerIsInvalid(): self
    {
        return new self('One of the selected author answers is not available.');
    }
}
