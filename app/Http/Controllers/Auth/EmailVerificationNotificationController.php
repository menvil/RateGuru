<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\SendEmailVerificationNotificationAction;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EmailVerificationNotificationController extends Controller
{
    /**
     * Send a new email verification notification.
     */
    public function store(Request $request, SendEmailVerificationNotificationAction $sendNotification): RedirectResponse
    {
        $user = $request->user();

        assert($user instanceof User);

        if (! $sendNotification->execute($user)) {
            return redirect()->intended(route('dashboard', absolute: false));
        }

        return back()->with('status', 'verification-link-sent');
    }
}
