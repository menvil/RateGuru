<?php

namespace App\Support\Database;

use Illuminate\Database\QueryException;

/**
 * Whether a failed write broke `users.username`'s uniqueness — two writes
 * racing for the same handle — rather than any other constraint, on every
 * engine the application runs on.
 */
final class UsernameUniqueViolation
{
    public static function matches(QueryException $exception): bool
    {
        return UniqueColumnViolation::on($exception, 'users', 'username');
    }
}
