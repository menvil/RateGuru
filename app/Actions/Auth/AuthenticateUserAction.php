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

        // The attempt is counted before the slow password check, not after
        // it: parallel attempts each see the others, so the limit holds
        // however many arrive at once. A successful sign-in clears it.
        if (RateLimiter::hit($throttleKey) > self::MAX_ATTEMPTS) {
            $this->lockOut($throttleKey, $request);
        }

        if (! Auth::attempt([
            'email' => $validated['email'],
            'password' => $validated['password'],
        ], (bool) ($validated['remember'] ?? false))) {
            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        // Sign-in gate after credential verification: living sanctions
        // (limited/banned/shadowbanned) may authenticate; a Deleted
        // tombstone and a Persona are refused — with the generic failure
        // message, never revealing that either exists. Anonymization
        // already scrambles tombstone credentials and a Persona is never
        // given a password; this is the explicit boundary for when one
        // reaches the row anyway.
        $user = Auth::user();

        if ($user !== null && ! $user->canSignIn()) {
            Auth::logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

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

        $this->lockOut($throttleKey, $request);
    }

    private function lockOut(string $throttleKey, Request $request): never
    {
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
        return self::throttleKeyFor($email, $request->ip());
    }

    /**
     * The cache key failed sign-ins for an address from a client are counted
     * under: hashed, as cache keys travel — into Sentry's cache breadcrumbs
     * and spans, say — and the address and the IP are personal data.
     */
    public static function throttleKeyFor(string $email, ?string $ip): string
    {
        return 'login:'.hash('sha256', Str::transliterate(Str::lower($email)).'|'.$ip);
    }
}
