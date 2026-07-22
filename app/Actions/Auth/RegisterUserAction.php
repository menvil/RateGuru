<?php

namespace App\Actions\Auth;

use App\Actions\Users\GenerateUniqueUsernameAction;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class RegisterUserAction
{
    public const int MAX_CREATE_ATTEMPTS = 3;

    public function __construct(
        private readonly GenerateUniqueUsernameAction $generateUniqueUsername,
    ) {}

    /** @param array{name: string, email: string, password: string} $validated */
    public function execute(array $validated): User
    {
        $password = Hash::make($validated['password']);
        $user = null;

        for ($attempt = 1; $attempt <= self::MAX_CREATE_ATTEMPTS; $attempt++) {
            try {
                $username = $this->generateUniqueUsername->handle($validated['name']);
            } catch (RuntimeException $exception) {
                throw ValidationException::withMessages([
                    'name' => $exception->getMessage(),
                ]);
            }

            try {
                $user = DB::transaction(fn (): User => User::create([
                    'name' => $validated['name'],
                    'username' => $username,
                    'email' => $validated['email'],
                    'password' => $password,
                ]));

                break;
            } catch (QueryException $exception) {
                if (! $this->isUsernameUniqueConstraintViolation($exception)) {
                    throw $exception;
                }

                if ($attempt === self::MAX_CREATE_ATTEMPTS) {
                    throw ValidationException::withMessages([
                        'name' => 'Unable to create a unique username. Please try a different name.',
                    ]);
                }
            }
        }

        if (! $user instanceof User) {
            throw new RuntimeException('Registration did not create a user.');
        }

        event(new Registered($user));
        Auth::login($user);

        return $user;
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
