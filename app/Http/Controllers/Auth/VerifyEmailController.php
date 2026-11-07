<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\VerifyEmailAction;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;

class VerifyEmailController extends Controller
{
    /**
     * Mark the authenticated user's email address as verified.
     */
    public function __invoke(EmailVerificationRequest $request, VerifyEmailAction $verifyEmail): RedirectResponse
    {
        $user = $request->user();

        assert($user instanceof User);

        $verifyEmail->execute($user, (string) $request->route('hash'));

        // Confirmed only when the address on the account now is verified: a
        // link for an address changed since it was sent confirms nothing.
        if (! $user->hasVerifiedEmail()) {
            return redirect()->intended(route('dashboard', absolute: false));
        }

        return redirect()->intended(route('dashboard', absolute: false).'?verified=1');
    }
}
