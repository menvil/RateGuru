<?php

namespace App\Actions\Auth;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AuthenticateUserAction
{
    private const MAX_ATTEMPTS = 5;

    /** @param array{email: string, password: string, remember?: bool|string} $validated */
    public function execute(array $validated, Request $request): void
    {
        $throttleKey = $this->throttleKey($validated['email'], $request);

        $this->ensureIsNotRateLimited($throttleKey, $request);

        if (! Auth::attempt([
            'email' => $validated['email'],
            'password' => $validated['password'],
        ], (bool) ($validated['remember'] ?? false))) {
            RateLimiter::hit($throttleKey);

            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        RateLimiter::clear($throttleKey);
    }

    private function ensureIsNotRateLimited(string $throttleKey, Request $request): void
    {
        if (! RateLimiter::tooManyAttempts($throttleKey, self::MAX_ATTEMPTS)) {
            return;
        }

        event(new Lockout($request));

        $seconds = RateLimiter::availableIn($throttleKey);

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    private function throttleKey(string $email, Request $request): string
    {
        return Str::transliterate(Str::lower($email).'|'.$request->ip());
    }
}
