<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Users\GenerateUniqueUsernameAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterUserRequest;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;

class RegisteredUserController extends Controller
{
    public const int MAX_CREATE_ATTEMPTS = 3;

    /**
     * Display the registration view.
     */
    public function create(): View
    {
        return view('auth.register');
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws ValidationException
     */
    public function store(
        RegisterUserRequest $request,
        GenerateUniqueUsernameAction $generateUniqueUsername,
    ): RedirectResponse {
        $validated = $request->validated();

        $password = Hash::make($validated['password']);

        for ($attempt = 1; $attempt <= self::MAX_CREATE_ATTEMPTS; $attempt++) {
            try {
                $username = $generateUniqueUsername->handle($validated['name']);
            } catch (RuntimeException $exception) {
                throw ValidationException::withMessages([
                    'name' => $exception->getMessage(),
                ]);
            }

            try {
                $user = User::create([
                    'name' => $validated['name'],
                    'username' => $username,
                    'email' => $validated['email'],
                    'password' => $password,
                ]);

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

        event(new Registered($user));

        Auth::login($user);

        return redirect(route('dashboard', absolute: false));
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
