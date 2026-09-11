<?php

namespace App\Actions\Auth\Concerns;

use App\Actions\Users\GenerateUniqueUsernameAction;
use App\Exceptions\Auth\CannotGenerateUsernameException;
use App\Models\User;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The username-collision retry every registration path shares.
 *
 * A username is generated from the person's name, the row is created inside
 * a transaction, and a unique-constraint violation on `username` — two
 * registrations racing for the same generated handle — simply tries the next
 * candidate. Any other QueryException (an email collision, a social identity
 * claimed meanwhile) is somebody else's decision and propagates untouched.
 */
trait CreatesUserWithUniqueUsername
{
    public const int MAX_CREATE_ATTEMPTS = 3;

    /**
     * @param  Closure(string): User  $create  persists the user, and whatever must land with it, inside the transaction
     *
     * @throws CannotGenerateUsernameException when no unique username could be generated or created
     */
    private function createUserWithUniqueUsername(
        GenerateUniqueUsernameAction $generateUniqueUsername,
        string $name,
        Closure $create,
    ): User {
        for ($attempt = 1; $attempt <= self::MAX_CREATE_ATTEMPTS; $attempt++) {
            try {
                $username = $generateUniqueUsername->handle($name);
            } catch (QueryException $exception) {
                // The generator queries the users table; a database failure
                // there is an infrastructure error, not "no username could be
                // settled", and must propagate like every other one.
                throw $exception;
            } catch (RuntimeException $exception) {
                throw CannotGenerateUsernameException::becauseGeneratorFailed($exception);
            }

            try {
                return DB::transaction(fn (): User => $create($username));
            } catch (QueryException $exception) {
                if (! $this->isUsernameUniqueConstraintViolation($exception)) {
                    throw $exception;
                }
            }
        }

        throw CannotGenerateUsernameException::afterExhaustingAttempts();
    }

    private function isUsernameUniqueConstraintViolation(QueryException $exception): bool
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
