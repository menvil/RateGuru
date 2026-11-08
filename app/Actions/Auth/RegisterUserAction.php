<?php

namespace App\Actions\Auth;

use App\Actions\Auth\Concerns\CreatesUserWithUniqueUsername;
use App\Actions\Users\GenerateUniqueUsernameAction;
use App\Exceptions\Auth\CannotGenerateUsernameException;
use App\Models\User;
use App\Support\Database\UniqueColumnViolation;
use App\Support\Locale\LocaleManager;
use Illuminate\Auth\Events\Registered;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final class RegisterUserAction
{
    use CreatesUserWithUniqueUsername;

    public function __construct(
        private readonly GenerateUniqueUsernameAction $generateUniqueUsername,
        private readonly LocaleManager $locales,
    ) {}

    /** @param array{name: string, email: string, password: string} $validated */
    public function execute(array $validated): User
    {
        $password = Hash::make($validated['password']);

        try {
            $user = $this->createUserWithUniqueUsername(
                $this->generateUniqueUsername,
                $validated['name'],
                fn (string $username): User => User::create([
                    'name' => $validated['name'],
                    'username' => $username,
                    'email' => $validated['email'],
                    // The language the person registered in, captured at the one
                    // moment we are certain of it. SetLocale has already resolved
                    // it for this request, so this is the site they were actually
                    // looking at — not a guess, and not the fallback.
                    //
                    // Without this the column stays NULL, preferredLocale() has
                    // nothing to prefer, and every later mail to this account
                    // falls back to whichever browser happens to be asking.
                    'locale' => $this->locales->enabledOrDefault(app()->getLocale()),
                    'password' => $password,
                ]),
            );
        } catch (CannotGenerateUsernameException $exception) {
            throw ValidationException::withMessages([
                'name' => __('auth.username_unavailable'),
            ]);
        } catch (QueryException $exception) {
            // The form checked the address before this write, but a second
            // registration with it — or the same form sent twice — can still
            // claim it first. That one is told the address is taken.
            if (! UniqueColumnViolation::on($exception, 'users', 'email')) {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'email' => __('validation.unique', ['attribute' => __('validation.attributes.email')]),
            ]);
        }

        event(new Registered($user));
        Auth::login($user);

        return $user;
    }
}
