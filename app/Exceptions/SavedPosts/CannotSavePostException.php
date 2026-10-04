<?php

namespace App\Exceptions\SavedPosts;

use RuntimeException;

class CannotSavePostException extends RuntimeException
{
    public static function postNotViewable(): self
    {
        return new self('This post cannot be saved because it is not viewable.');
    }

    public static function userNotAllowed(): self
    {
        return new self('This account cannot modify saved posts.');
    }
}
