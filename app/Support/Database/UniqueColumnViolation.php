<?php

namespace App\Support\Database;

use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Which column's uniqueness a failed write broke — two writes racing for the
 * same value past a check that could only look before either of them wrote —
 * on every engine the application runs on.
 */
final class UniqueColumnViolation
{
    /**
     * The first of these columns of $table whose uniqueness the write broke,
     * or null when it broke something else.
     *
     * @param  list<string>  $columns
     */
    public static function among(QueryException $exception, string $table, array $columns): ?string
    {
        foreach ($columns as $column) {
            if (self::on($exception, $table, $column)) {
                return $column;
            }
        }

        return null;
    }

    public static function on(QueryException $exception, string $table, string $column): bool
    {
        if (! $exception instanceof UniqueConstraintViolationException) {
            return false;
        }

        if ($exception->columns !== []) {
            return in_array($column, $exception->columns, true);
        }

        if ($exception->index !== null) {
            return str_contains(strtolower($exception->index), $column);
        }

        $message = strtolower($exception->getPrevious()?->getMessage() ?? '');

        return str_contains($message, "{$table}.{$column}")
            || str_contains($message, "{$table}_{$column}_unique")
            || str_contains($message, "({$column})");
    }
}
