<?php

namespace App\Support\Database;

use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Whether a failed write broke `users.username`'s uniqueness — two writes
 * racing for the same handle — rather than any other constraint, on every
 * engine the application runs on.
 */
final class UsernameUniqueViolation
{
    public static function matches(QueryException $exception): bool
    {
        if (! $exception instanceof UniqueConstraintViolationException) {
            return false;
        }

        if ($exception->columns !== []) {
            return in_array('username', $exception->columns, true);
        }

        if ($exception->index !== null) {
            return str_contains(strtolower($exception->index), 'username');
        }

        $message = strtolower($exception->getPrevious()?->getMessage() ?? '');

        return str_contains($message, 'users.username')
            || str_contains($message, 'users_username_unique')
            || str_contains($message, '(username)');
    }
}
