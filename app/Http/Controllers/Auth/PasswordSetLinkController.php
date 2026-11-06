<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\SendPasswordResetLinkAction;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PasswordSetLinkController extends Controller
{
    /**
     * Email the signed-in account a link to set a password.
     *
     * The way an account created through Google or Facebook gets a password:
     * the ordinary password-reset email, sent to the account's own address,
     * so the new password is only ever set by whoever reads that mailbox —
     * never by whoever merely holds the session.
     */
    public function store(Request $request, SendPasswordResetLinkAction $sendResetLink): RedirectResponse
    {
        $user = $request->user();

        assert($user instanceof User);

        try {
            $sendResetLink->execute($user->email);
        } catch (ValidationException $exception) {
            // Shown in the password section, not beside the profile's own
            // email field, which reads the default error bag.
            throw $exception->errorBag('passwordSetLink');
        }

        return back()->with('status', 'password-set-link-sent');
    }
}
