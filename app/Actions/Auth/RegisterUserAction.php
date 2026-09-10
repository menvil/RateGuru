<?php

namespace App\Actions\Auth;

use App\Actions\Auth\Concerns\CreatesUserWithUniqueUsername;
use App\Actions\Users\GenerateUniqueUsernameAction;
use App\Exceptions\Auth\CannotGenerateUsernameException;
use App\Models\User;
use App\Support\Locale\LocaleManager;
use Illuminate\Auth\Events\Registered;
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
                    'locale' => $this->locales->normalize(app()->getLocale()),
                    'password' => $password,
                ]),
            );
        } catch (CannotGenerateUsernameException $exception) {
            throw ValidationException::withMessages([
                'name' => $exception->getMessage(),
            ]);
        }

        event(new Registered($user));
        Auth::login($user);

        return $user;
    }
}
